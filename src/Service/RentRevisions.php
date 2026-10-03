<?php

namespace Base\Estate\Service;

use Base\Entity\User;
use Base\Estate\Entity\Lease;
use Base\Estate\Enum\ActivityKind;
use Base\Estate\Enum\LeaseStatus;
use Base\Estate\Repository\LeaseRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The yearly rent revision of a French residential lease (loi du 6 juillet 1989, art. 17-1):
 * on the lease's anniversary, the rent (charges aside) follows the IRL - the new rent is the
 * rent times the new quarter's index over the lease's reference index, never more. The
 * reference then becomes the new quarter; the tenants get the letter; the next rent calls
 * carry the new rent.
 */
class RentRevisions
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LeaseRepository $leases,
        private readonly Activities $activities,
        private readonly EstateMailer $mailer,
    ) {
    }

    /** The next anniversary the rent can be revised on: a year after the start or the last revision. */
    public function nextOn(Lease $lease, ?\DateTimeImmutable $today = null): \DateTimeImmutable
    {
        $today ??= new \DateTimeImmutable('today');
        $on = $lease->getRevisedOn() ? $lease->getRevisedOn()->modify('+1 year') : $lease->getStartOn()->modify('+1 year');
        while ($on < $today->modify('-11 months')) {
            $on = $on->modify('+1 year');
        }

        return $on;
    }

    /** The rent (cents, charges aside) at the new index. */
    public function rentAt(Lease $lease, string $newIndex): int
    {
        $old = (float) $lease->getIndexValue();
        $new = (float) str_replace(',', '.', $newIndex);
        if ($old <= 0 || $new <= 0) {
            throw new \InvalidArgumentException('Both indices are needed.');
        }

        return (int) round($lease->getRent() * $new / $old);
    }

    /**
     * The revision applied: the new rent from $on, the new reference index, the activity
     * line and the tenants' letter. Returns the former rent.
     */
    public function apply(Lease $lease, string $newQuarter, string $newIndex, \DateTimeImmutable $on, ?User $by = null): int
    {
        $former = $lease->getRent();
        $formerQuarter = $lease->getIndexQuarter();
        $formerIndex = $lease->getIndexValue();
        $rent = $this->rentAt($lease, $newIndex);
        $lease->setRent($rent)->setIndexQuarter($newQuarter)->setIndexValue($newIndex)->setRevisedOn($on);
        $this->activities->log($lease->getOrganisation(), ActivityKind::OTHER, sprintf('Loyer révisé (IRL %s) : %s → %s', $newQuarter, number_format($former / 100, 2, ',', ' ').' €', number_format($rent / 100, 2, ',', ' ').' €'), $lease->getUnit(), $rent - $former);
        $this->entityManager->flush();

        $this->mailer->send(
            array_values(array_filter(array_map(fn ($party) => $party->getEmail(), $lease->getTenants()))),
            'Révision annuelle de votre loyer — '.$lease->getUnit()->getName(),
            'rent_revision',
            ['lease' => $lease, 'organisation' => $lease->getOrganisation(), 'former' => $former, 'rent' => $rent, 'on' => $on, 'formerQuarter' => $formerQuarter, 'formerIndex' => $formerIndex],
            $lease->getOrganisation()->getEmail(),
        );

        return $former;
    }

    /**
     * The running leases whose revision falls within $days and that have a reference index -
     * for the back office's dashboard.
     *
     * @return list<array{lease: Lease, on: \DateTimeImmutable}>
     */
    public function due(int $days = 45): array
    {
        $today = new \DateTimeImmutable('today');
        $due = [];
        foreach ($this->leases->findBy(['status' => LeaseStatus::ACTIVE]) as $lease) {
            if (null === $lease->getIndexValue()) {
                continue;
            }
            $on = $this->nextOn($lease, $today);
            if ($on <= $today->modify('+'.$days.' days')) {
                $due[] = ['lease' => $lease, 'on' => $on];
            }
        }
        usort($due, fn ($a, $b) => $a['on'] <=> $b['on']);

        return $due;
    }
}
