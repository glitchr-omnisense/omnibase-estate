<?php

namespace Base\Estate\Repository;

use Base\Estate\Entity\Contractor;
use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\WorkOrder;
use Base\Estate\Enum\WorkOrderStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WorkOrder> */
class WorkOrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkOrder::class);
    }

    /** @return list<WorkOrder> */
    public function findOpen(Organisation $organisation): array
    {
        return $this->createQueryBuilder('w')->join('w.unit', 'u')
            ->where('u.organisation = :organisation')->setParameter('organisation', $organisation)
            ->andWhere('w.status NOT IN (:closed)')->setParameter('closed', [WorkOrderStatus::CLOSED, WorkOrderStatus::CANCELLED])
            ->orderBy('w.urgency', 'DESC')->addOrderBy('w.createdAt', 'ASC')
            ->getQuery()->getResult();
    }

    /** @param list<Contractor> $contractors @return list<WorkOrder> */
    public function findForContractors(array $contractors): array
    {
        if (!$contractors) {
            return [];
        }

        return $this->createQueryBuilder('w')
            ->where('w.contractor IN (:contractors)')->setParameter('contractors', $contractors)
            ->orderBy('w.status', 'ASC')->addOrderBy('w.visitAt', 'ASC')->addOrderBy('w.createdAt', 'DESC')
            ->getQuery()->getResult();
    }

    /** Spent on works over a period, in cents (closed orders' invoices). */
    public function spent(Organisation $organisation, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->createQueryBuilder('w')->join('w.unit', 'u')
            ->select('COALESCE(SUM(w.invoiceAmount), 0)')
            ->where('u.organisation = :organisation')->setParameter('organisation', $organisation)
            ->andWhere('w.closedAt BETWEEN :from AND :to')
            ->setParameter('from', $from)->setParameter('to', $to)
            ->getQuery()->getSingleScalarResult();
    }
}
