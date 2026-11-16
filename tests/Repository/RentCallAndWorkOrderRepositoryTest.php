<?php

namespace Tests\Base\Estate\Repository;

use Base\Database\Repository\Finders;
use Base\Testing\Engines;
use Base\Estate\Entity\Building;
use Base\Estate\Entity\Contractor;
use Base\Estate\Entity\Lease;
use Base\Estate\Entity\LeaseParty;
use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\Payment;
use Base\Estate\Entity\RentCall;
use Base\Estate\Entity\Room;
use Base\Estate\Entity\Unit;
use Base\Estate\Entity\UnitPhoto;
use Base\Estate\Entity\WorkOrder;
use Base\Estate\Entity\WorkOrderEvent;
use Base\Estate\Enum\RentStatus;
use Base\Estate\Enum\Urgency;
use Base\Estate\Enum\WorkOrderStatus;
use Base\Estate\Repository\RentCallRepository;
use Base\Estate\Repository\WorkOrderRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The bundle's queries, written once in the language, give the same rows on both engines, from the same SQLite rows
 * (Base\Testing\Engines: omnibase's description of the entities, both engines over one database) - in the style of
 * omnibridge/eloquent's RepositoryParityTest.
 */
final class RentCallAndWorkOrderRepositoryTest extends TestCase
{
    private const CLASSES = [Organisation::class, Building::class, Unit::class, Room::class, UnitPhoto::class, Lease::class, LeaseParty::class, RentCall::class, Payment::class, Contractor::class, WorkOrder::class, WorkOrderEvent::class];

    private static Engines $engines;
    /** @var array<string, int> */
    private static array $ids = [];

    public static function setUpBeforeClass(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is needed to run both engines.');
        }
        try {
            self::$engines = Engines::of(self::CLASSES);
        } catch (\Error $e) {
            self::markTestSkipped('omnibridge/doctrine and omnibridge/eloquent are needed to run both engines ('.$e->getMessage().').');
        }

        $alpha = new Organisation('Alpha', 'alpha');
        $beta = new Organisation('Beta', 'beta');
        $a1 = new Unit($alpha, 'A1', 'a1');
        $b1 = new Unit($beta, 'B1', 'b1');
        $la = new Lease($a1, 'LA', new \DateTimeImmutable('2025-01-01'));
        $lb = new Lease($b1, 'LB', new \DateTimeImmutable('2025-01-01'));
        $call = static fn (Lease $lease, string $month, string $dueOn, RentStatus $status): RentCall => (new RentCall($lease, new \DateTimeImmutable($month)))->setDueOn(new \DateTimeImmutable($dueOn))->setStatus($status);
        $calls = [
            $call($la, '2026-10-01', '2026-10-05', RentStatus::CALLED),
            $call($lb, '2026-10-01', '2026-10-05', RentStatus::CALLED),
            $call($la, '2026-09-01', '2026-09-05', RentStatus::PAID),
            $call($lb, '2026-11-01', '2026-11-20', RentStatus::CALLED),
            $call($la, '2026-11-01', '2026-11-05', RentStatus::PAID),
        ];
        $order = static fn (Unit $unit, string $title, Urgency $urgency, WorkOrderStatus $status, string $createdAt): WorkOrder => (new WorkOrder($unit, $title))->setUrgency($urgency)->setStatus($status)->setCreatedAt(new \DateTimeImmutable($createdAt));
        $orders = [
            $order($a1, 'w1', Urgency::URGENT, WorkOrderStatus::REPORTED, '2026-11-01 10:00'),
            $order($b1, 'w2', Urgency::NORMAL, WorkOrderStatus::REPORTED, '2026-10-15 10:00'),
            $order($a1, 'w3', Urgency::LOW, WorkOrderStatus::CLOSED, '2026-10-01 10:00'),
            $order($a1, 'w4', Urgency::NORMAL, WorkOrderStatus::ASSIGNED, '2026-11-02 10:00'),
        ];
        self::$engines->seed($alpha, $beta, $a1, $b1, $la, $lb, ...$calls, ...$orders);
        self::$ids = ['alpha' => (int) $alpha->getId(), 'beta' => (int) $beta->getId()];
    }

    /** @return array<string, array{0: string}> */
    public static function backends(): array
    {
        return Engines::backends();
    }

    private function finders(string $backend): Finders
    {
        return self::$engines->finders($backend);
    }

    /** @return list<string> the calls as "lease month" */
    private static function calls(array $calls): array
    {
        return array_map(static fn (RentCall $call): string => $call->getLease()->getReference().' '.$call->getPeriod()->format('m'), $calls);
    }

    #[DataProvider('backends')]
    public function testTheRentCallsByOrganisationName(string $backend): void
    {
        $finders = $this->finders($backend);
        $calls = new RentCallRepository($finders);
        $alpha = $finders->of(Organisation::class)->find(self::$ids['alpha']);
        $beta = $finders->of(Organisation::class)->find(self::$ids['beta']);
        $today = new \DateTimeImmutable('2026-11-15');

        self::assertSame(['LA 10', 'LB 10'], self::calls($calls->findOverdueFirst([$beta, $alpha], $today, 10)), 'the due day, then the organisation\'s name');
        self::assertSame(['LA 10'], self::calls($calls->findOverdueFirst([$alpha, $beta], $today, 1)));
        self::assertSame(['LB 10'], self::calls($calls->findOverdueFirst([$beta], $today, 10)));
        self::assertSame(['LB 11', 'LA 11'], self::calls($calls->findInMonthNotOverdue([$alpha, $beta], new \DateTimeImmutable('2026-11-10'), $today, 10)), 'the ones still due first, then the paid ones');
        self::assertSame(['LB 11'], self::calls($calls->findInMonthNotOverdue([$alpha, $beta], new \DateTimeImmutable('2026-11-10'), $today, 1)));
        self::assertSame([], $calls->findInMonthNotOverdue([$alpha, $beta], new \DateTimeImmutable('2026-10-10'), $today, 10), 'October\'s are late: listed apart');

        // The dashboard's figures, read as values (findValuesBy) through two associations.
        $byOrganisation = $calls->overdueByOrganisation([$alpha, $beta], $today);
        self::assertSame([self::$ids['alpha'], self::$ids['beta']], array_keys($byOrganisation));
        self::assertSame([1, 1], array_column($byOrganisation, 'count'));
        $months = $calls->monthTotals([$alpha, $beta], new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-11-30'));
        self::assertSame(['2026-09', '2026-10', '2026-11'], array_keys($months));
        self::assertSame([1, 2, 2], array_column($months, 'count'));
        self::assertSame(['LA 10'], self::calls($calls->findForOrganisation($alpha, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'))));
    }

    #[DataProvider('backends')]
    public function testTheOpenWorkOrdersByOrganisationName(string $backend): void
    {
        $finders = $this->finders($backend);
        $orders = new WorkOrderRepository($finders);
        $alpha = $finders->of(Organisation::class)->find(self::$ids['alpha']);
        $beta = $finders->of(Organisation::class)->find(self::$ids['beta']);
        $titles = static fn (array $found): array => array_map(static fn (WorkOrder $o): string => $o->getTitle(), $found);

        self::assertSame(['w1', 'w4', 'w2'], $titles($orders->findOpenFirst([$beta, $alpha], 10)), 'by organisation, then urgency, then age; closed left out');
        self::assertSame(['w1', 'w4'], $titles($orders->findOpenFirst([$alpha, $beta], 2)));
        self::assertSame(['w2'], $titles($orders->findOpenFirst([$beta], 10)));
    }
}
