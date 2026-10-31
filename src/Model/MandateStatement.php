<?php

namespace Base\Estate\Model;

use Base\Estate\Entity\Mandate;
use Base\Estate\Entity\MandateExpense;
use Base\Estate\Entity\MandateFee;
use Base\Estate\Entity\MandatePayout;
use Base\Estate\Entity\RentCall;
use Base\Estate\Entity\Unit;

/**
 * An owner's statement for a period (compte rendu de gérance): their
 * account with the agency, from the owner's side - what the agency holds
 * for them is positive.
 *
 * - the opening balance: everything below, from the mandate's start until
 *   the day before the period;
 * - each home's rents called in the period and collected in it (the rents
 *   are the owner's when collected: called and unpaid, they are listed, not
 *   counted);
 * - the agency's fees on them, before tax and their VAT;
 * - the expenses charged (work orders' invoices, the syndic's calls...);
 * - the payouts;
 * - the closing balance: opening + collected - fees - expenses - payouts,
 *   and what a payout would be (less the mandate's reserve).
 *
 * Built from estate's records - the same the books are posted from
 * (Ledger\ManagementBooks) - so it holds with or without omnibase/ledger.
 * A rent counts while the mandate runs: from its first day to its last.
 */
final class MandateStatement
{
    /**
     * @param list<array{unit: Unit, called: int, collected: int}> $units
     * @param list<array{date: \DateTimeImmutable, label: string, unit: Unit, amount: int}> $rentLines
     * @param list<array{date: \DateTimeImmutable, label: string, base: int, amount: int, vat: int, total: int}> $feeLines
     * @param list<array{date: \DateTimeImmutable, label: string, amount: int, expense: MandateExpense}> $expenseLines
     * @param list<array{date: \DateTimeImmutable, label: string, amount: int, payout: MandatePayout}> $payoutLines
     * @param list<array{call: RentCall, remaining: int}> $unpaid
     */
    public function __construct(
        public readonly Mandate $mandate,
        public readonly \DateTimeImmutable $from,
        public readonly \DateTimeImmutable $to,
        public readonly int $opening,
        public readonly array $units,
        public readonly array $rentLines,
        public readonly array $feeLines,
        public readonly array $expenseLines,
        public readonly array $payoutLines,
        public readonly array $unpaid,
    ) {
    }

    /**
     * @param iterable<RentCall>       $rentCalls the rent calls of the mandate's homes (others are left out)
     * @param iterable<MandateFee>     $fees      the mandate's fees
     * @param iterable<MandateExpense> $expenses  the mandate's expenses (those not posted are left out)
     * @param iterable<MandatePayout>  $payouts   the mandate's payouts
     */
    public static function of(Mandate $mandate, \DateTimeImmutable $from, \DateTimeImmutable $to, iterable $rentCalls, iterable $fees = [], iterable $expenses = [], iterable $payouts = []): self
    {
        $from = $from->setTime(0, 0);
        $to = $to->setTime(0, 0);
        $in = fn (\DateTimeInterface $date) => $date->format('Y-m-d') >= $from->format('Y-m-d') && $date->format('Y-m-d') <= $to->format('Y-m-d');
        $before = fn (\DateTimeInterface $date) => $date->format('Y-m-d') < $from->format('Y-m-d');
        $opening = 0;
        $units = $rents = $feeLines = $expenseLines = $payoutLines = $unpaid = [];
        foreach ($mandate->getUnits() as $unit) {
            $units[self::key($unit)] = ['unit' => $unit, 'called' => 0, 'collected' => 0];
        }

        foreach ($rentCalls as $call) {
            $unit = $call->getLease()->getUnit();
            if (!$mandate->hasUnit($unit)) {
                continue;
            }
            $key = self::key($unit);
            $units[$key] ??= ['unit' => $unit, 'called' => 0, 'collected' => 0];
            $covered = $mandate->covers($call->getPeriod());
            if ($covered && $in($call->getPeriod())) {
                $units[$key]['called'] += $call->getTotal();
            }
            $paidByEnd = 0;
            foreach ($call->getPayments() as $payment) {
                $paidOn = $payment->getPaidOn();
                if ($paidOn->format('Y-m-d') <= $to->format('Y-m-d')) {
                    $paidByEnd += $payment->getAmount();
                }
                if (!$mandate->covers($paidOn)) {
                    continue;
                }
                if ($before($paidOn)) {
                    $opening += $payment->getAmount();
                } elseif ($in($paidOn)) {
                    $units[$key]['collected'] += $payment->getAmount();
                    $rents[] = ['date' => \DateTimeImmutable::createFromInterface($paidOn), 'label' => sprintf('Loyer %s — %s', $call->getPeriod()->format('m/Y'), $call->getLease()->getTenantNames() ?: $call->getLease()->getReference()), 'unit' => $unit, 'amount' => $payment->getAmount()];
                }
            }
            if ($covered && $call->getPeriod()->format('Y-m-d') <= $to->format('Y-m-d') && $call->getTotal() > $paidByEnd) {
                $unpaid[] = ['call' => $call, 'remaining' => $call->getTotal() - $paidByEnd];
            }
        }

        foreach ($fees as $fee) {
            if ($before($fee->getDate())) {
                $opening -= $fee->getTotal();
            } elseif ($in($fee->getDate())) {
                $feeLines[] = ['date' => $fee->getDate(), 'label' => $fee->getLabel(), 'base' => $fee->getBase(), 'amount' => $fee->getAmount(), 'vat' => $fee->getVat(), 'total' => $fee->getTotal()];
            }
        }

        foreach ($expenses as $expense) {
            if (!$expense->isPosted()) {
                continue;
            }
            if ($before($expense->getDate())) {
                $opening -= $expense->getAmount();
            } elseif ($in($expense->getDate())) {
                $expenseLines[] = ['date' => $expense->getDate(), 'label' => trim($expense->getLabel().' — '.$expense->getSupplier(), ' —'), 'amount' => $expense->getAmount(), 'expense' => $expense];
            }
        }

        foreach ($payouts as $payout) {
            if ($before($payout->getIssuedOn())) {
                $opening -= $payout->getAmount();
            } elseif ($in($payout->getIssuedOn())) {
                $payoutLines[] = ['date' => $payout->getIssuedOn(), 'label' => sprintf('Reversement %s', $payout->getReference() ?? ''), 'amount' => $payout->getAmount(), 'payout' => $payout];
            }
        }

        $byDate = fn (array $a, array $b) => $a['date'] <=> $b['date'];
        usort($rents, $byDate);
        usort($feeLines, $byDate);
        usort($expenseLines, $byDate);
        usort($payoutLines, $byDate);
        usort($unpaid, fn (array $a, array $b) => $a['call']->getPeriod() <=> $b['call']->getPeriod());
        $units = array_values($units);
        usort($units, fn (array $a, array $b) => strcmp($a['unit']->getName(), $b['unit']->getName()));

        return new self($mandate, $from, $to, $opening, $units, $rents, $feeLines, $expenseLines, $payoutLines, $unpaid);
    }

    public function getCalled(): int { return array_sum(array_column($this->units, 'called')); }
    public function getCollected(): int { return array_sum(array_column($this->rentLines, 'amount')); }
    /** The fees as charged: before tax and their VAT. */
    public function getFees(): int { return array_sum(array_column($this->feeLines, 'total')); }
    public function getFeesExcludingTax(): int { return array_sum(array_column($this->feeLines, 'amount')); }
    public function getFeesVat(): int { return array_sum(array_column($this->feeLines, 'vat')); }
    public function getExpenses(): int { return array_sum(array_column($this->expenseLines, 'amount')); }
    public function getPayouts(): int { return array_sum(array_column($this->payoutLines, 'amount')); }
    public function getClosing(): int { return $this->opening + $this->getCollected() - $this->getFees() - $this->getExpenses() - $this->getPayouts(); }
    /** What a payout at the period's end would be: the closing balance less the mandate's reserve. */
    public function getAvailable(): int { return max(0, $this->getClosing() - $this->mandate->getReserve()); }
    /** The rents called and not paid at the period's end: the tenants', not yet the owner's. */
    public function getUnpaid(): int { return array_sum(array_column($this->unpaid, 'remaining')); }
    public function getName(): string { return $this->mandate->getMandator()->getName(); }

    public function getPeriodKey(): string
    {
        return Period::key($this->from, $this->to);
    }

    public function getPeriodLabel(): string
    {
        return Period::label($this->from, $this->to);
    }

    private static function key(Unit $unit): string
    {
        return null !== $unit->getId() ? 'id:'.$unit->getId() : 'obj:'.spl_object_id($unit);
    }
}
