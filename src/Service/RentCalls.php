<?php

namespace Base\Estate\Service;

use Base\Estate\Entity\Lease;
use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\RentCall;
use Base\Estate\Enum\ActivityKind;
use Base\Estate\Enum\RentStatus;
use Base\Estate\Repository\LeaseRepository;
use Base\Estate\Repository\RentCallRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The monthly rent: a call per running lease (once per month, whatever runs
 * it twice), posted to the books, sent to the tenants; then, past its due
 * date, marked late and reminded.
 */
class RentCalls
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LeaseRepository $leases,
        private readonly RentCallRepository $calls,
        private readonly ActivityLog $activities,
        private readonly EstateMailer $mailer,
        private readonly ?Books $books = null,
        #[Autowire('%estate.reminder_days%')] private readonly int $reminderDays = 5,
    ) {
    }

    /** @return list<RentCall> the calls made (none twice for a lease and a month) */
    public function call(\DateTimeImmutable $month, ?Organisation $organisation = null, bool $notify = true): array
    {
        $made = [];
        foreach ($this->leases->findRunningIn($month, $organisation) as $lease) {
            if ($call = $this->callOne($lease, $month, $notify)) {
                $made[] = $call;
            }
        }
        $this->entityManager->flush();

        return $made;
    }

    public function callOne(Lease $lease, \DateTimeImmutable $month, bool $notify = true): ?RentCall
    {
        $period = $month->modify('first day of this month')->setTime(0, 0);
        if ($this->calls->findOneBy(['lease' => $lease, 'period' => $period])) {
            return null;
        }

        $call = new RentCall($lease, $period);
        $this->entityManager->persist($call);
        $this->entityManager->flush();

        // Notices go out a week before the month: that is when it happened, or today if later.
        $at = min(new \DateTimeImmutable(), $period->modify('-6 days')->setTime(9, 0));
        $call->setCalledAt($at);
        $this->activities->log($lease->getOrganisation(), ActivityKind::RENT_CALLED,
            sprintf('Loyer de %s appelé : %s', $this->month($period), $lease->getTenantNames() ?: $lease->getReference()),
            $lease->getUnit(), $call->getTotal(), $at);
        $this->books?->rentCalled($call);

        if ($notify) {
            $this->mailer->send(
                array_map(fn ($party) => $party->getEmail(), $lease->getTenants()),
                sprintf('Avis d\'échéance — %s — %s', $this->month($period), $lease->getUnit()->getName()),
                'rent_call',
                ['call' => $call, 'lease' => $lease, 'organisation' => $lease->getOrganisation()],
                $lease->getOrganisation()->getEmail(),
            );
        }

        return $call;
    }

    /** @return list<RentCall> the calls past due, now marked late; reminded once after reminder_days */
    public function markLate(\DateTimeImmutable $today, bool $remind = true): array
    {
        $late = [];
        foreach ($this->calls->findOverdue($today) as $call) {
            if (RentStatus::CALLED === $call->getStatus() || RentStatus::PARTIAL === $call->getStatus()) {
                $call->setStatus(RentStatus::LATE);
                $this->activities->log($call->getLease()->getOrganisation(), ActivityKind::RENT_LATE,
                    sprintf('Loyer de %s en retard : %s', $this->month($call->getPeriod()), $call->getLease()->getTenantNames()),
                    $call->getLease()->getUnit(), $call->getRemaining());
            }
            $late[] = $call;
            if ($remind && null === $call->getRemindedAt() && $call->getDueOn()->modify('+'.$this->reminderDays.' days') <= $today) {
                $this->mailer->send(
                    array_map(fn ($party) => $party->getEmail(), $call->getLease()->getTenants()),
                    sprintf('Rappel — loyer de %s — %s', $this->month($call->getPeriod()), $call->getLease()->getUnit()->getName()),
                    'rent_reminder',
                    ['call' => $call, 'lease' => $call->getLease(), 'organisation' => $call->getLease()->getOrganisation()],
                    $call->getLease()->getOrganisation()->getEmail(),
                );
                $call->setRemindedAt(new \DateTimeImmutable());
            }
        }
        $this->entityManager->flush();

        return $late;
    }

    public function month(\DateTimeImmutable $period): string
    {
        static $months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

        return $months[(int) $period->format('n') - 1].' '.$period->format('Y');
    }
}
