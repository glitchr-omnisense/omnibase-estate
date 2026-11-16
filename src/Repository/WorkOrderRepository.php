<?php

namespace Base\Estate\Repository;

use Base\Estate\Entity\Contractor;
use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\WorkOrder;
use Base\Estate\Enum\WorkOrderStatus;
use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Key;
use Base\Database\Repository\Finder\Parser;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<WorkOrder> */
class WorkOrderRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, WorkOrder::class);
    }

    /** @return list<WorkOrder> */
    public function findOpen(Organisation $organisation): array
    {
        return $this->findBy($this->open([$organisation]), ['urgency' => 'DESC', 'createdAt' => 'ASC']);
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
        $open = $this->open($organisations);

        return ['count' => $this->count($open), 'quoted' => (int) $this->sumOfQuoteAmountBy($open)];
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

        return $this->findBy($this->open($organisations), ['unit.organisation.name' => 'ASC', 'unit.organisation.id' => 'ASC', 'urgency' => 'DESC', 'createdAt' => 'ASC', 'id' => 'ASC'], $limit);
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

        return (int) $this->sumOfInvoiceAmountBy(['unit.organisation' => $organisations, $this->closedBetween($from, $to)]);
    }

    /**
     * @param list<Organisation> $organisations findOpen()'s conditions, for several organisations
     *
     * @return array<int|string, mixed>
     */
    private function open(array $organisations): array
    {
        return [
            'unit.organisation' => $organisations,
            Group::of(Parser::SEPARATOR_AND, Criterion::of('status', [WorkOrderStatus::CLOSED, WorkOrderStatus::CANCELLED], Parser::OPTION_NOT_EQUAL)),
        ];
    }

    /** w.closedAt BETWEEN :from AND :to */
    private function closedBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): Group
    {
        return Group::of(Parser::SEPARATOR_AND,
            Criterion::of('closedAt', $from, Parser::OPTION_GREATER_EQUAL),
            Criterion::of('closedAt', $to, Parser::OPTION_LOWER_EQUAL),
        );
    }

    /** @param list<Contractor> $contractors @return list<WorkOrder> */
    public function findForContractors(array $contractors): array
    {
        if (!$contractors) {
            return [];
        }

        return $this->findBy(['contractor' => Key::of(array_values($contractors))], ['status' => 'ASC', 'visitAt' => 'ASC', 'createdAt' => 'DESC']);
    }

    /** Spent on works over a period, in cents (closed orders' invoices). */
    public function spent(Organisation $organisation, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->sumOfInvoiceAmountBy(['unit.organisation' => $organisation, $this->closedBetween($from, $to)]);
    }
}
