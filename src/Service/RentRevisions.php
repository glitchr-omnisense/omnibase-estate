<?php

namespace Base\Estate\Service;

use Base\Entity\User;
use Base\Estate\Entity\Lease;
use Base\Estate\Enum\ActivityKind;
use Base\Estate\Enum\RevisionRule;
use Base\Estate\Exception\IndexUnavailableException;
use Base\Estate\Index\OfficialIndex;
use Base\Estate\Model\IndexCaps;
use Base\Estate\Model\RevisionProposal;
use Base\Estate\Repository\LeaseRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The yearly rent revision of a French residential lease (loi du 6 juillet 1989, art. 17-1):
 * on the lease's anniversary, the rent (charges aside) follows the IRL - the new rent is the
 * rent times the new quarter's index over the lease's reference index, never more. The
 * reference then becomes the new quarter; the tenants get the letter; the next rent calls
 * carry the new rent.
 *
 * With omnisense/omnistate's statistics (omnistate/insee), propose() reads the new quarter's
 * value from INSEE (Index\OfficialIndex); without it, or when INSEE does not answer, the value
 * is typed by hand as before. Which quarter, the anniversary, the caps (Model\IndexCaps) and the
 * rounding are decided here; a revision is applied only by a person (apply()).
 *
 * A commercial or professional lease (Lease::getKind()) follows the index it names (ILC or ILAT)
 * and its own rule (Enum\RevisionRule): its indexation clause, yearly on the anniversary, measured
 * from the reference quarter with no year lost (the contract's rule; art. 17-1 is the home's); or
 * the triennial revision of a commercial lease (Code de commerce, art. L145-38, version of 20 June
 * 2014): asked three years at least after the tenant's entry or the last price, effective from the
 * day it is asked, the change capped by the index's variation since the last price. Which quarter
 * that variation ends at the article does not say: the team types it, estate gives the ceiling.
 */
class RentRevisions
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LeaseRepository $leases,
        private readonly ActivityLog $activities,
        private readonly EstateMailer $mailer,
        private readonly ?OfficialIndex $index = null,
    ) {
    }

    /** Whether the official index is read (omnistate's statistics installed). */
    public function readsIndex(): bool
    {
        return null !== $this->index;
    }

    /** The next anniversary the rent can be revised on: a year after the start or the last revision. */
    public function nextOn(Lease $lease, ?\DateTimeImmutable $today = null): \DateTimeImmutable
    {
        $today ??= new \DateTimeImmutable('today');
        if (RevisionRule::TRIENNIAL === $lease->getRevisionRule()) {
            // Art. L145-38: three years at least after the entry, or after the day the last price applied.
            return ($lease->getRevisedOn() ?? $lease->getStartOn())->modify('+3 years');
        }
        $on = $lease->getRevisedOn() ? $lease->getRevisedOn()->modify('+1 year') : $lease->getStartOn()->modify('+1 year');
        while ($on < $today->modify('-11 months')) {
            $on = $on->modify('+1 year');
        }

        return $on;
    }

    /**
     * The rent (cents, charges aside) at the new index: the rent times the new value over the
     * reference value ($fromIndex, else the lease's), the change capped when the law caps the new
     * quarter's (Model\IndexCaps), rounded to the cent.
     */
    public function rentAt(Lease $lease, string $newIndex, ?string $newQuarter = null, ?string $fromIndex = null): int
    {
        return $this->change($lease, $newIndex, $newQuarter, $fromIndex)[0];
    }

    /**
     * The quarter a revision on $on is measured at, and the one it is measured from: the lease's
     * reference quarter (« T2 2025 ») the years after. A year whose revision was not claimed within
     * the year is lost (art. 17-1, I): the change is then measured from the year before the new one.
     *
     * @return array{quarter: string, from: string, waived: int}|null null without a reference quarter
     */
    public function quarters(Lease $lease, \DateTimeImmutable $on): ?array
    {
        if (1 !== preg_match('/^T([1-4])\s*(\d{4})$/', (string) $lease->getIndexQuarter(), $m)) {
            return null;
        }
        $since = $lease->getRevisedOn() ?? $lease->getStartOn();
        $years = max(1, (int) $on->format('Y') - (int) $since->format('Y'));
        $year = (int) $m[2] + $years;
        if (RevisionRule::YEARLY !== $lease->getRevisionRule()) {
            // A clause's indexation: the whole change since the reference, no year lost (art. 17-1 is a home's).
            return ['quarter' => sprintf('T%d %d', $m[1], $year), 'from' => sprintf('T%d %d', $m[1], $m[2]), 'waived' => 0];
        }

        return ['quarter' => sprintf('T%d %d', $m[1], $year), 'from' => 1 === $years ? sprintf('T%d %d', $m[1], $m[2]) : sprintf('T%d %d', $m[1], $year - 1), 'waived' => $years - 1];
    }

    /**
     * The lease's next revision: its anniversary, its quarters and, with the official index read,
     * the new value, its source and the rent it gives - or why it waits. Nothing is saved.
     */
    public function propose(Lease $lease, ?\DateTimeImmutable $today = null): RevisionProposal
    {
        $on = $this->nextOn($lease, $today);
        $index = $lease->getIndexName();
        if (RevisionRule::NONE === $lease->getRevisionRule()) {
            return new RevisionProposal($lease, RevisionProposal::NONE, $on, $index);
        }
        $quarters = $this->quarters($lease, $on);
        if (null === $quarters || null === $lease->getIndexValue()) {
            return new RevisionProposal($lease, RevisionProposal::NO_REFERENCE, $on, $index);
        }
        if (RevisionRule::TRIENNIAL === $lease->getRevisionRule()) {
            // The quarter the variation ends at is the team's to choose (art. L145-38 does not name it).
            return new RevisionProposal($lease, RevisionProposal::MANUAL, $on, $index, $quarters['from'], $lease->getIndexValue(), note: RevisionProposal::NOTE_TRIENNIAL);
        }
        $fromValue = 0 === $quarters['waived'] ? $lease->getIndexValue() : null;
        $base = [$lease, RevisionProposal::MANUAL, $on, $index, $quarters['from'], $fromValue, $quarters['quarter']];
        if (IndexCaps::spansCap($index, $quarters['from'], $quarters['quarter'], $lease->isSmallBusiness())) {
            // Several years across the ILC's capped quarters: the « définitivement acquis » part is not computed here.
            return new RevisionProposal(...$base, note: RevisionProposal::NOTE_CAP_SPAN);
        }
        if (null === $this->index) {
            return new RevisionProposal(...$base, waived: $quarters['waived']);
        }
        try {
            $value = $this->index->value($index, $quarters['quarter']);
            if (null === $fromValue) {
                $fromValue = $this->index->value($index, $quarters['from'])->value;
            }
        } catch (IndexUnavailableException) {
            $base[1] = RevisionProposal::UNAVAILABLE;

            return new RevisionProposal(...$base, waived: $quarters['waived']);
        }
        $base[5] = $fromValue;
        if (null === $value->value || null === $fromValue) {
            $base[1] = RevisionProposal::WAITING;

            return new RevisionProposal(...$base, value: $value, waived: $quarters['waived']);
        }
        [$rent, $capped] = $this->change($lease, $value->value, $quarters['quarter'], $fromValue);
        $base[1] = RevisionProposal::READY;

        return new RevisionProposal(...$base, value: $value, rent: $rent, waived: $quarters['waived'], capped: $capped);
    }

    /**
     * The proposals of the running leases whose revision falls within $days.
     *
     * @return list<RevisionProposal>
     */
    public function proposals(int $days = 45): array
    {
        return array_map(fn (array $due) => $this->propose($due['lease']), $this->due($days));
    }

    /** @return array{int, ?float} the rent and the cap applied, if it was reached */
    private function change(Lease $lease, string $newIndex, ?string $newQuarter, ?string $fromIndex): array
    {
        $old = (float) str_replace(',', '.', $fromIndex ?? (string) $lease->getIndexValue());
        $new = (float) str_replace(',', '.', $newIndex);
        if ($old <= 0 || $new <= 0) {
            throw new \InvalidArgumentException('Both indices are needed.');
        }
        $ratio = $new / $old;
        // A cap is on a yearly change: a commercial lease's change over several years is not capped by it (IndexCaps::spansCap()).
        $yearly = 'IRL' === $lease->getIndexName() || IndexCaps::isOneYear($lease->getIndexQuarter(), (string) $newQuarter);
        $cap = null === $newQuarter || !$yearly ? null : IndexCaps::limit($lease->getIndexName(), $newQuarter, $lease->getUnit()->getPostcode(), $lease->isSmallBusiness());
        $capped = null;
        if (null !== $cap && $ratio > 1 + $cap) {
            $ratio = 1 + $cap;
            $capped = $cap;
        }

        return [(int) round($lease->getRent() * $ratio), $capped];
    }

    /**
     * The revision applied: the new rent from $on, the new reference index, the activity
     * line and the tenants' letter. Returns the former rent. $fromIndex: the value the change is
     * measured from when it is not the lease's (years not claimed, quarters()).
     */
    public function apply(Lease $lease, string $newQuarter, string $newIndex, \DateTimeImmutable $on, ?User $by = null, ?string $fromIndex = null): int
    {
        $former = $lease->getRent();
        $formerQuarter = $lease->getIndexQuarter();
        $formerIndex = $fromIndex ?? $lease->getIndexValue();
        $rent = $this->rentAt($lease, $newIndex, $newQuarter, $fromIndex);
        $lease->setRent($rent)->setIndexQuarter($newQuarter)->setIndexValue($newIndex)->setRevisedOn($on);
        $this->activities->log($lease->getOrganisation(), ActivityKind::OTHER, sprintf('Loyer révisé (%s %s) : %s → %s', $lease->getIndexName(), $newQuarter, number_format($former / 100, 2, ',', ' ').' €', number_format($rent / 100, 2, ',', ' ').' €'), $lease->getUnit(), $rent - $former);
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
        foreach ($this->leases->findActive() as $lease) {
            if (null === $lease->getIndexValue() || RevisionRule::NONE === $lease->getRevisionRule()) {
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
