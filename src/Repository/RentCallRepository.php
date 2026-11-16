<?php

namespace Base\Estate\Repository;

use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\RentCall;
use Base\Estate\Entity\Unit;
use Base\Estate\Enum\RentStatus;
use Base\Database\Repository\Finder\Aggregate;
use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Parser;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<RentCall> */
class RentCallRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, RentCall::class);
    }

    /** @return list<RentCall> unpaid, due before a day */
    public function findOverdue(\DateTimeImmutable $today, ?Organisation $organisation = null): array
    {
        return $this->findBy($this->overdue(null === $organisation ? null : [$organisation], $today), ['dueOn' => 'ASC']);
    }

    /** @return list<RentCall> an organisation's calls between two months (inclusive), oldest first */
    public function findForOrganisation(Organisation $organisation, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->findBy(['lease.unit.organisation' => $organisation, $this->periods($from, $to)], ['period' => 'ASC']);
    }

    /**
     * What is late for these organisations together: how many calls are unpaid past their due
     * day, and what remains of them (findOverdue()'s calls, read as two figures: what remains of
     * each, never below zero, summed here).
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
        $calls = $this->findBy($this->overdue($organisations, $today));
        $total = 0;
        foreach ($calls as $call) {
            $total += max(0, $call->getRent() + $call->getCharges() + $call->getVat() - $call->getPaid());
        }

        return ['count' => \count($calls), 'total' => $total];
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

        return $this->findBy($this->overdue($organisations, $today), self::BY_ORGANISATION, $limit);
    }

    /**
     * Per organisation, how many of its calls are late and the oldest of them (its id), in the
     * order of their oldest late call.
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
        $by = [];
        foreach ($this->findValuesBy(['organisation' => 'lease.unit.organisation', 'id' => 'id'], $this->overdue($organisations, $today), ['dueOn' => 'ASC', 'id' => 'ASC']) as $row) {
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
        $months = [];
        foreach ($this->findValuesBy(
            ['period' => 'period', 'rent' => Aggregate::sum('rent'), 'charges' => Aggregate::sum('charges'), 'vat' => Aggregate::sum('vat'), 'paid' => Aggregate::sum('paid'), 'calls' => Aggregate::count()],
            ['lease.unit.organisation' => $organisations, $this->periods($from, $to)],
            ['period' => 'ASC'],
        ) as $row) {
            $period = $row['period'] instanceof \DateTimeInterface ? $row['period'] : new \DateTimeImmutable((string) $row['period']);
            $key = $period->format('Y-m');
            $months[$key] ??= ['called' => 0, 'received' => 0, 'count' => 0];
            $months[$key]['called'] += (int) $row['rent'] + (int) $row['charges'] + (int) $row['vat'];
            $months[$key]['received'] += (int) $row['paid'];
            $months[$key]['count'] += (int) $row['calls'];
        }

        return $months;
    }

    /**
     * A month's calls for these organisations that are not late (those are listed apart), at
     * most $limit, their lease and home with them: the ones still due first, by due day, then
     * the paid ones.
     *
     * @param list<Organisation> $organisations
     *
     * @return list<RentCall>
     */
    public function findInMonthNotOverdue(array $organisations, \DateTimeImmutable $month, \DateTimeImmutable $today, int $limit): array
    {
        if ([] === $organisations || $limit < 1) {
            return [];
        }
        $criteria = ['lease.unit.organisation' => $organisations, 'period' => $month->modify('first day of this month')->setTime(0, 0)];
        $due = $this->findBy([...$criteria, Group::of(Parser::SEPARATOR_AND,
            Criterion::of('status', RentStatus::PAID, Parser::OPTION_NOT_EQUAL),
            Criterion::of('dueOn', $today, Parser::OPTION_GREATER_EQUAL),
        )], self::BY_ORGANISATION, $limit);
        if (\count($due) >= $limit) {
            return $due;
        }

        return [...$due, ...$this->findBy([...$criteria, 'status' => RentStatus::PAID], self::BY_ORGANISATION, $limit - \count($due))];
    }

    /** The dashboard's order: the due day, then the organisation's name. */
    private const BY_ORGANISATION = ['dueOn' => 'ASC', 'lease.unit.organisation.name' => 'ASC', 'lease.unit.organisation.id' => 'ASC', 'id' => 'ASC'];

    /**
     * findOverdue()'s conditions, for several organisations (null: every organisation's).
     *
     * @param list<Organisation>|null $organisations
     *
     * @return array<int|string, mixed>
     */
    private function overdue(?array $organisations, \DateTimeImmutable $today): array
    {
        $criteria = [Group::of(Parser::SEPARATOR_AND,
            Criterion::of('status', RentStatus::PAID, Parser::OPTION_NOT_EQUAL),
            Criterion::of('dueOn', $today, Parser::OPTION_LOWER),
        )];
        if (null !== $organisations) {
            $criteria['lease.unit.organisation'] = $organisations;
        }

        return $criteria;
    }

    /** r.period between the first days of two months (inclusive). */
    private function periods(\DateTimeImmutable $from, \DateTimeImmutable $to): Group
    {
        return Group::of(Parser::SEPARATOR_AND,
            Criterion::of('period', $from->modify('first day of this month')->setTime(0, 0), Parser::OPTION_GREATER_EQUAL),
            Criterion::of('period', $to->modify('first day of this month')->setTime(0, 0), Parser::OPTION_LOWER_EQUAL),
        );
    }

    /** @return list<RentCall> the calls an online payment was started for and not seen paid (estate:payment:check) */
    public function findOnlinePending(): array
    {
        return $this->findBy([Group::of(Parser::SEPARATOR_AND,
            Criterion::of('onlineReference', null, Parser::OPTION_NOT_NULL),
            Criterion::of('status', RentStatus::PAID, Parser::OPTION_NOT_EQUAL),
        )]);
    }

    /**
     * @param list<Unit> $units
     *
     * @return list<RentCall> the rent calls of these homes, oldest first (their leases and payments load with them)
     */
    public function forUnits(array $units): array
    {
        $units = array_values(array_filter($units, fn (Unit $unit) => null !== $unit->getId()));
        if ([] === $units) {
            return [];
        }

        return $this->findBy(['lease.unit' => $units], ['period' => 'ASC', 'id' => 'ASC']);
    }

    public function lastReceiptNumber(string $prefix): ?string
    {
        $last = $this->maxOfReceiptNumberBy([Group::of(Parser::SEPARATOR_AND, Criterion::of('receiptNumber', $prefix, Parser::OPTION_STARTING_WITH))]);

        return null === $last ? null : (string) $last;
    }
}
