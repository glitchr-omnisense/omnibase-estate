<?php

namespace Base\Estate\Repository;

use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\RentCall;
use Base\Estate\Entity\Unit;
use Base\Estate\Enum\RentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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

    /**
     * What is late for these organisations together: how many calls are unpaid past their due
     * day, and what remains of them (findOverdue()'s calls, read as two figures).
     *
     * @param list<Organisation> $organisations
     *
     * @return array{count: int, total: int}
     */
    public function overdueTotals(array $organisations, \DateTimeImmutable $today): array
    {
        if ([] === $organisations) {
            return ['count' => 0, 'total' => 0];
        }
        $remaining = 'r.rent + r.charges + r.vat - r.paid';
        $row = $this->overdue($organisations, $today)
            ->select('COUNT(r.id) AS calls', "COALESCE(SUM(CASE WHEN $remaining > 0 THEN $remaining ELSE 0 END), 0) AS total")
            ->getQuery()->getSingleResult();

        return ['count' => (int) $row['calls'], 'total' => (int) $row['total']];
    }

    /**
     * The oldest calls late for these organisations, at most $limit, their lease and home with
     * them: the due day first, then the organisation's name, as the dashboard lists them.
     *
     * @param list<Organisation> $organisations
     *
     * @return list<RentCall>
     */
    public function findOverdueFirst(array $organisations, \DateTimeImmutable $today, int $limit): array
    {
        if ([] === $organisations) {
            return [];
        }

        return $this->overdue($organisations, $today)->addSelect('l', 'u')->join('u.organisation', 'o')
            ->orderBy('r.dueOn', 'ASC')->addOrderBy('o.name', 'ASC')->addOrderBy('o.id', 'ASC')->addOrderBy('r.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * Per organisation, how many of its calls are late and the oldest of them (its id).
     *
     * @param list<Organisation> $organisations
     *
     * @return array<int, array{count: int, first: int}> by organisation id
     */
    public function overdueByOrganisation(array $organisations, \DateTimeImmutable $today): array
    {
        if ([] === $organisations) {
            return [];
        }
        $rows = $this->overdue($organisations, $today)
            ->select('IDENTITY(u.organisation) AS organisation', 'r.id AS id')
            ->orderBy('r.dueOn', 'ASC')->addOrderBy('r.id', 'ASC')
            ->getQuery()->getScalarResult();
        $by = [];
        foreach ($rows as $row) {
            $by[(int) $row['organisation']] ??= ['count' => 0, 'first' => (int) $row['id']];
            ++$by[(int) $row['organisation']]['count'];
        }

        return $by;
    }

    /**
     * The rents called and received for these organisations, month by month between two
     * months (inclusive), and how many calls: the sums of findForOrganisation()'s calls.
     *
     * @param list<Organisation> $organisations
     *
     * @return array<string, array{called: int, received: int, count: int}> by month (Y-m), the months with calls only
     */
    public function monthTotals(array $organisations, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ([] === $organisations) {
            return [];
        }
        $rows = $this->createQueryBuilder('r')->join('r.lease', 'l')->join('l.unit', 'u')
            ->select('r.period AS period', 'SUM(r.rent + r.charges + r.vat) AS called', 'SUM(r.paid) AS received', 'COUNT(r.id) AS calls')
            ->where('u.organisation IN (:organisations)')->setParameter('organisations', $organisations)
            ->andWhere('r.period >= :from')->setParameter('from', $from->modify('first day of this month')->setTime(0, 0), 'date_immutable')
            ->andWhere('r.period <= :to')->setParameter('to', $to->modify('first day of this month')->setTime(0, 0), 'date_immutable')
            ->groupBy('r.period')
            ->getQuery()->getScalarResult();
        $months = [];
        foreach ($rows as $row) {
            $period = $row['period'] instanceof \DateTimeInterface ? $row['period'] : new \DateTimeImmutable($row['period']);
            $key = $period->format('Y-m');
            $months[$key] ??= ['called' => 0, 'received' => 0, 'count' => 0];
            $months[$key]['called'] += (int) $row['called'];
            $months[$key]['received'] += (int) $row['received'];
            $months[$key]['count'] += (int) $row['calls'];
        }

        return $months;
    }

    /**
     * A month's calls for these organisations that are not late (those are listed apart), at
     * most $limit, their lease and home with them: the ones still due first, by due day.
     *
     * @param list<Organisation> $organisations
     *
     * @return list<RentCall>
     */
    public function findInMonthNotOverdue(array $organisations, \DateTimeImmutable $month, \DateTimeImmutable $today, int $limit): array
    {
        if ([] === $organisations) {
            return [];
        }

        return $this->createQueryBuilder('r')->join('r.lease', 'l')->join('l.unit', 'u')->join('u.organisation', 'o')
            ->addSelect('l', 'u')
            ->addSelect('CASE WHEN r.status = :paid THEN 1 ELSE 0 END AS HIDDEN settled')
            ->where('u.organisation IN (:organisations)')->setParameter('organisations', $organisations)
            ->andWhere('r.period = :month')->setParameter('month', $month->modify('first day of this month')->setTime(0, 0), 'date_immutable')
            ->andWhere('r.status = :paid OR r.dueOn >= :today')->setParameter('paid', RentStatus::PAID)->setParameter('today', $today, 'date_immutable')
            ->orderBy('settled', 'ASC')->addOrderBy('r.dueOn', 'ASC')->addOrderBy('o.name', 'ASC')->addOrderBy('o.id', 'ASC')->addOrderBy('r.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /** @param list<Organisation> $organisations findOverdue()'s conditions, for several organisations */
    private function overdue(array $organisations, \DateTimeImmutable $today): QueryBuilder
    {
        return $this->createQueryBuilder('r')
            ->join('r.lease', 'l')->join('l.unit', 'u')
            ->where('r.status != :paid')->setParameter('paid', RentStatus::PAID)
            ->andWhere('r.dueOn < :today')->setParameter('today', $today, 'date_immutable')
            ->andWhere('u.organisation IN (:organisations)')->setParameter('organisations', $organisations);
    }

    /** @return list<RentCall> the calls an online payment was started for and not seen paid (estate:payment:check) */
    public function findOnlinePending(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.onlineReference IS NOT NULL')->andWhere('c.status != :paid')->setParameter('paid', RentStatus::PAID)
            ->getQuery()->getResult();
    }

    /**
     * @param list<Unit> $units
     *
     * @return list<RentCall> the rent calls of these homes, their leases and payments with them, oldest first
     */
    public function forUnits(array $units): array
    {
        $units = array_values(array_filter($units, fn (Unit $unit) => null !== $unit->getId()));
        if ([] === $units) {
            return [];
        }

        return $this->createQueryBuilder('r')->select('r', 'l', 'p')
            ->join('r.lease', 'l')->leftJoin('r.payments', 'p')
            ->where('l.unit IN (:units)')->setParameter('units', $units)
            ->orderBy('r.period', 'ASC')->addOrderBy('r.id', 'ASC')
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
