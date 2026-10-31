<?php

namespace Base\Estate\Repository;

use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\RentCall;
use Base\Estate\Enum\RentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<RentCall> */
class RentCallRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RentCall::class);
    }

    /** @return list<RentCall> unpaid, due before a day */
    public function findOverdue(\DateTimeImmutable $today, ?Organisation $organisation = null): array
    {
        $query = $this->createQueryBuilder('r')
            ->join('r.lease', 'l')->join('l.unit', 'u')
            ->where('r.status != :paid')->setParameter('paid', RentStatus::PAID)
            ->andWhere('r.dueOn < :today')->setParameter('today', $today, 'date_immutable')
            ->orderBy('r.dueOn', 'ASC');
        if ($organisation) {
            $query->andWhere('u.organisation = :organisation')->setParameter('organisation', $organisation);
        }

        return $query->getQuery()->getResult();
    }

    /** @return list<RentCall> an organisation's calls between two months (inclusive), oldest first */
    public function findForOrganisation(Organisation $organisation, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.lease', 'l')->join('l.unit', 'u')
            ->where('u.organisation = :organisation')->setParameter('organisation', $organisation)
            ->andWhere('r.period >= :from')->setParameter('from', $from->modify('first day of this month')->setTime(0, 0), 'date_immutable')
            ->andWhere('r.period <= :to')->setParameter('to', $to->modify('first day of this month')->setTime(0, 0), 'date_immutable')
            ->orderBy('r.period', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return list<RentCall> the calls an online payment was started for and not seen paid (estate:payment:check) */
    public function findOnlinePending(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.onlineReference IS NOT NULL')->andWhere('c.status != :paid')->setParameter('paid', RentStatus::PAID)
            ->getQuery()->getResult();
    }

    public function lastReceiptNumber(string $prefix): ?string
    {
        return $this->createQueryBuilder('r')
            ->select('MAX(r.receiptNumber)')
            ->where('r.receiptNumber LIKE :prefix')->setParameter('prefix', $prefix.'%')
            ->getQuery()->getSingleScalarResult();
    }
}
