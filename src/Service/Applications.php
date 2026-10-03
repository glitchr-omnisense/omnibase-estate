<?php

namespace Base\Estate\Service;

use Base\Estate\Entity\Application;
use Base\Estate\Entity\Lease;
use Base\Estate\Entity\LeaseParty;
use Base\Estate\Enum\ActivityKind;
use Base\Estate\Enum\ApplicationStatus;
use Base\Estate\Enum\PartyRole;
use Base\Estate\Repository\ApplicationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Applications to rent a free home, from received to let: the applicant is told at every
 * step by e-mail (received, invited to visit, declined); the management is told of each new
 * one. Accepting one makes the lease - the applicant its tenant, the home's rent, charges
 * and deposit, starting on the date they asked - takes the home off the market and, when
 * asked, declines the others. Decided applications are deleted three months on (purge).
 */
class Applications
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ApplicationRepository $applications,
        private readonly Activities $activities,
        private readonly EstateMailer $mailer,
    ) {
    }

    public function submit(Application $application): Application
    {
        $this->entityManager->persist($application);
        $unit = $application->getUnit();
        $this->activities->log($unit->getOrganisation(), ActivityKind::OTHER, 'Candidature reçue : '.$application->getName(), $unit);
        $this->entityManager->flush();

        $this->mailer->send($unit->getOrganisation()->getEmail(), sprintf('[Candidature] %s — %s', $unit->getName(), $application->getName()), 'application_received', ['application' => $application], $application->getEmail());
        $this->mailer->send($application->getEmail(), 'Votre candidature — '.$unit->getName(), 'application_ack', ['application' => $application, 'organisation' => $unit->getOrganisation()], $unit->getOrganisation()->getEmail());

        return $application;
    }

    public function shortlist(Application $application): void
    {
        $application->setStatus(ApplicationStatus::SHORTLISTED);
        $this->entityManager->flush();
    }

    /** The applicant invited to visit at $at, with the management's words. */
    public function inviteToVisit(Application $application, \DateTimeImmutable $at, ?string $message = null): void
    {
        $application->setStatus(ApplicationStatus::VISIT)->setVisitAt($at);
        $this->entityManager->flush();
        $this->mailer->send($application->getEmail(), 'Visite de '.$application->getUnit()->getName(), 'application_visit', ['application' => $application, 'organisation' => $application->getOrganisation(), 'note' => $message], $application->getOrganisation()->getEmail());
    }

    /** Declined, and told so politely (the management's words added, when it has some). */
    public function decline(Application $application, ?string $message = null, bool $notify = true): void
    {
        if (!$application->getStatus()->isOpen()) {
            return;
        }
        $application->setStatus(ApplicationStatus::DECLINED);
        $this->entityManager->flush();
        if ($notify) {
            $this->mailer->send($application->getEmail(), 'Votre candidature — '.$application->getUnit()->getName(), 'application_declined', ['application' => $application, 'organisation' => $application->getOrganisation(), 'note' => $message], $application->getOrganisation()->getEmail());
        }
    }

    /**
     * Accepted: the lease made (the applicant its tenant, from the date they asked or $startOn),
     * the home no longer offered, and - with $declineOthers - the other open applications declined.
     */
    public function accept(Application $application, \DateTimeImmutable $startOn, bool $declineOthers = false): Lease
    {
        $unit = $application->getUnit();
        $lease = (new Lease($unit, $this->reference($unit->getOrganisation()->getName(), $startOn), $startOn))->setDeposit($unit->getDeposit());
        $lease->addParty((new LeaseParty($application->getFirstName(), $application->getLastName(), $application->getEmail(), PartyRole::TENANT))->setPhone($application->getPhone()));
        $unit->getLeases()->add($lease);
        $unit->setAvailableFrom(null);
        $this->entityManager->persist($lease);
        $application->setStatus(ApplicationStatus::ACCEPTED)->setLease($lease);
        $this->activities->log($unit->getOrganisation(), ActivityKind::LEASE_STARTED, sprintf('Bail %s : %s, à partir du %s', $lease->getReference(), $application->getName(), $startOn->format('d/m/Y')), $unit);
        $this->entityManager->flush();

        if ($declineOthers) {
            foreach ($this->applications->openFor($unit) as $other) {
                $this->decline($other);
            }
        }

        return $lease;
    }

    /** Applications decided more than three months ago, deleted: how many. */
    public function purge(int $months = 3): int
    {
        $expired = $this->applications->expired($months);
        foreach ($expired as $application) {
            $this->entityManager->remove($application);
        }
        $this->entityManager->flush();

        return \count($expired);
    }

    /** "MA-2026-003": the company's initials (Meyers & Associée), the year, the year's next number. */
    private function reference(string $company, \DateTimeImmutable $startOn): string
    {
        $words = array_filter(preg_split('/[^\pL]+/u', $company), fn ($w) => mb_strlen($w) > 1 && !\in_array(mb_strtoupper($w), ['SCI', 'SARL', 'SAS', 'SA', 'ET', 'DE', 'DU', 'LA', 'LE', 'LES'], true));
        $initials = mb_strtoupper(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), \array_slice(array_values($words), 0, 3)))) ?: 'B';
        $prefix = $initials.'-'.$startOn->format('Y').'-';
        $taken = $this->entityManager->createQueryBuilder()->select('l.reference')->from(Lease::class, 'l')
            ->where('l.reference LIKE :prefix')->setParameter('prefix', $prefix.'%')->getQuery()->getSingleColumnResult();
        $next = 1 + max([0, ...array_map(fn ($r) => (int) substr((string) $r, \strlen($prefix)), $taken)]);

        return $prefix.sprintf('%03d', $next);
    }
}
