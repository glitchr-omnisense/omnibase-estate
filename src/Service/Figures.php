<?php

namespace Base\Estate\Service;

use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\RentCall;
use Base\Estate\Entity\Unit;
use Base\Estate\Enum\PaymentMethod;
use Base\Estate\Repository\RentCallRepository;
use Base\Estate\Repository\UnitRepository;
use Base\Estate\Repository\WorkOrderRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * An organisation in numbers, for its associates and its back office: what
 * is let, what was called and received this year, what is late, the last
 * months side by side, what works cost, how rents are paid.
 */
class Figures
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly RentCallRepository $calls,
        private readonly WorkOrderRepository $workOrders,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @return array<string, mixed> */
    public function of(Organisation $organisation, ?\DateTimeImmutable $today = null): array
    {
        $today ??= new \DateTimeImmutable('today');
        $units = $this->units->findByOrganisation($organisation);
        $lettable = array_filter($units, fn (Unit $u) => 'parking' !== $u->getKind()->value);
        $let = array_filter($lettable, fn (Unit $u) => $u->isLet($today));

        $yearStart = $today->setDate((int) $today->format('Y'), 1, 1);
        $calls = $this->calls->findForOrganisation($organisation, $yearStart, $today);
        $called = array_sum(array_map(fn (RentCall $c) => $c->getTotal(), $calls));
        $received = array_sum(array_map(fn (RentCall $c) => $c->getPaid(), $calls));
        $late = array_filter($this->calls->findOverdue($today, $organisation), fn (RentCall $c) => !$c->isPaid());

        $months = [];
        for ($i = 5; $i >= 0; --$i) {
            $month = $today->modify('first day of this month')->modify("-$i months")->setTime(0, 0);
            $months[$month->format('Y-m')] = ['month' => $month, 'called' => 0, 'received' => 0];
        }
        foreach ($this->calls->findForOrganisation($organisation, $today->modify('first day of this month')->modify('-5 months'), $today) as $call) {
            $key = $call->getPeriod()->format('Y-m');
            if (isset($months[$key])) {
                $months[$key]['called'] += $call->getTotal();
                $months[$key]['received'] += $call->getPaid();
            }
        }
        $max = max(1, ...array_values(array_map(fn ($m) => $m['called'], $months)));

        $methods = [];
        foreach ($calls as $call) {
            foreach ($call->getPayments() as $payment) {
                $methods[$payment->getMethod()->value] = ($methods[$payment->getMethod()->value] ?? 0) + $payment->getAmount();
            }
        }

        return [
            'units' => \count($units),
            'lettable' => \count($lettable),
            'let' => \count($let),
            'vacant' => array_values(array_filter($lettable, fn (Unit $u) => !$u->isLet($today))),
            'occupancy' => \count($lettable) ? round(100 * \count($let) / \count($lettable)) : 0,
            'monthly' => array_sum(array_map(fn (Unit $u) => $u->getCurrentLease($today)?->getMonthly() ?? 0, $let)),
            'called' => $called,
            'received' => $received,
            'rate' => $called ? round(100 * $received / $called) : 100,
            'late' => array_values($late),
            'lateTotal' => array_sum(array_map(fn (RentCall $c) => $c->getRemaining(), $late)),
            'months' => array_values($months),
            'monthsMax' => $max,
            'openWorkOrders' => $this->workOrders->findOpen($organisation),
            'worksSpent' => $this->workOrders->spent($organisation, $yearStart, $today->setTime(23, 59, 59)),
            'methods' => $methods,
            'online' => ($methods[PaymentMethod::CARD->value] ?? 0) + ($methods[PaymentMethod::SEPA_DEBIT->value] ?? 0),
        ];
    }
}
