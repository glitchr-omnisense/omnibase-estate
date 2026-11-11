<?php

namespace Base\Estate\Repository;

use Base\Estate\Entity\Contractor;
use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\WorkOrder;
use Base\Estate\Enum\WorkOrderStatus;
use Doctrine\ORM\QueryBuilder;
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

    /**
     * The work orders open for these organisations: how many, and their quotes' sum.
     *
     * @param list<Organisation> $organisations
     *
     * @return array{count: int, quoted: int}
     */
    public function openTotals(array $organisations): array
    {
        if ([] === $organisations) {
            return ['count' => 0, 'quoted' => 0];
        }
        $row = $this->open($organisations)->select('COUNT(w.id) AS orders', 'COALESCE(SUM(w.quoteAmount), 0) AS quoted')->getQuery()->getSingleResult();

        return ['count' => (int) $row['orders'], 'quoted' => (int) $row['quoted']];
    }

    /**
     * The first work orders open for these organisations, at most $limit, their home and
     * contractor with them: findOpen()'s order, the organisations by name.
     *
     * @param list<Organisation> $organisations
     *
     * @return list<WorkOrder>
     */
    public function findOpenFirst(array $organisations, int $limit): array
    {
        if ([] === $organisations) {
            return [];
        }

        return $this->open($organisations)->join('u.organisation', 'o')->leftJoin('w.contractor', 'c')->addSelect('u', 'c')
            ->orderBy('o.name', 'ASC')->addOrderBy('o.id', 'ASC')->addOrderBy('w.urgency', 'DESC')->addOrderBy('w.createdAt', 'ASC')->addOrderBy('w.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * Spent on works over a period by these organisations together, in cents: spent()'s sum.
     *
     * @param list<Organisation> $organisations
     */
    public function spentBy(array $organisations, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        if ([] === $organisations) {
            return 0;
        }

        return (int) $this->createQueryBuilder('w')->join('w.unit', 'u')
            ->select('COALESCE(SUM(w.invoiceAmount), 0)')
            ->where('u.organisation IN (:organisations)')->setParameter('organisations', $organisations)
            ->andWhere('w.closedAt BETWEEN :from AND :to')
            ->setParameter('from', $from)->setParameter('to', $to)
            ->getQuery()->getSingleScalarResult();
    }

    /** @param list<Organisation> $organisations findOpen()'s conditions, for several organisations */
    private function open(array $organisations): QueryBuilder
    {
        return $this->createQueryBuilder('w')->join('w.unit', 'u')
            ->where('u.organisation IN (:organisations)')->setParameter('organisations', $organisations)
            ->andWhere('w.status NOT IN (:closed)')->setParameter('closed', [WorkOrderStatus::CLOSED, WorkOrderStatus::CANCELLED]);
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
