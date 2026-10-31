<?php

namespace Base\Estate\Model;

use Base\Estate\Entity\Ownership;
use Base\Estate\Entity\OwnershipCall;
use Base\Estate\Entity\OwnershipInvoice;
use Base\Estate\Entity\OwnershipPayment;
use Base\Estate\Entity\OwnershipShare;
use Base\Estate\Entity\Payment;

/**
 * A co-owner's statement for a period (a month, a quarter, a year): their
 * account with the shared ownership, from the co-owner's side - what it
 * owes them is positive.
 *
 * - their share of the income: each rent received for one of its homes,
 *   divided by the shares in force the day it came in;
 * - their share of the expenses: each shared invoice's part, as posted;
 * - their payments on their calls for funds;
 * - the calls themselves, listed with what is left to pay (a provision
 *   called by hand is a request: what they pay on it counts, not the call);
 * - the opening balance (the same, from the start until the day before)
 *   and the closing one: opening + income - expenses + payments.
 *
 * Built from estate's records - the same the books are posted from
 * (Ledger\OwnershipBooks) - so it holds with or without omnibase/ledger.
 */
final class OwnershipStatement
{
    /**
     * @param list<array{date: \DateTimeImmutable, label: string, amount: int, part: int}> $incomeLines
     * @param list<array{date: \DateTimeImmutable, label: string, amount: int, part: int, invoice: OwnershipInvoice}> $expenseLines
     * @param list<array{call: OwnershipCall, paid: int, remaining: int}> $calls
     * @param list<array{date: \DateTimeImmutable, label: string, amount: int, payment: OwnershipPayment}> $paymentLines
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly Ownership $ownership,
        public readonly string $holder,
        public readonly ?OwnershipShare $share,
        public readonly \DateTimeImmutable $from,
        public readonly \DateTimeImmutable $to,
        public readonly int $opening,
        public readonly array $incomeLines,
        public readonly array $expenseLines,
        public readonly array $calls,
        public readonly array $paymentLines,
        public readonly array $warnings = [],
    ) {
    }

    /**
     * @param iterable<OwnershipInvoice> $invoices     the ownership's posted invoices
     * @param iterable<OwnershipCall>    $calls        the holder's calls (any holder's are left out)
     * @param iterable<Payment>          $rentPayments rents received for the ownership's homes
     */
    public static function of(Ownership $ownership, string $holder, \DateTimeImmutable $from, \DateTimeImmutable $to, iterable $invoices, iterable $calls, iterable $rentPayments): self
    {
        $from = $from->setTime(0, 0);
        $to = $to->setTime(0, 0);
        $opening = 0;
        $income = $expenses = $callRows = $payments = $warnings = [];
        $in = fn (\DateTimeInterface $date) => $date->format('Y-m-d') >= $from->format('Y-m-d') && $date->format('Y-m-d') <= $to->format('Y-m-d');
        $before = fn (\DateTimeInterface $date) => $date->format('Y-m-d') < $from->format('Y-m-d');

        foreach ($rentPayments as $payment) {
            $call = $payment->getRentCall();
            if (!$ownership->hasUnit($call->getLease()->getUnit()) || (!$before($payment->getPaidOn()) && !$in($payment->getPaidOn()))) {
                continue;
            }
            try {
                $part = $ownership->allocate($payment->getAmount(), $payment->getPaidOn())[$holder] ?? 0;
            } catch (\InvalidArgumentException) {
                $warnings[] = sprintf('Quotes-parts incomplètes au %s : loyer %s non réparti.', $payment->getPaidOn()->format('d/m/Y'), $call->getReference());
                continue;
            }
            if ($before($payment->getPaidOn())) {
                $opening += $part;
                continue;
            }
            if (0 !== $part) {
                $income[] = ['date' => $payment->getPaidOn(), 'label' => sprintf('Loyer %s — %s', $call->getPeriod()->format('m/Y'), $call->getLease()->getUnit()->getName()), 'amount' => $payment->getAmount(), 'part' => $part];
            }
        }

        foreach ($invoices as $invoice) {
            if (!$invoice->isPosted() || 0 === ($part = $invoice->getPart($holder))) {
                continue;
            }
            if ($before($invoice->getDate())) {
                $opening -= $part;
            } elseif ($in($invoice->getDate())) {
                $expenses[] = ['date' => $invoice->getDate(), 'label' => trim($invoice->getLabel().' — '.$invoice->getSupplier(), ' —'), 'amount' => $invoice->getSharedAmount() ?? $invoice->getAmount(), 'part' => $part, 'invoice' => $invoice];
            }
        }

        foreach ($calls as $call) {
            if ($call->getHolder() !== $holder) {
                continue;
            }
            $paidByEnd = 0;
            foreach ($call->getPayments() as $payment) {
                if ($payment->getPaidOn()->format('Y-m-d') > $to->format('Y-m-d')) {
                    continue;
                }
                $paidByEnd += $payment->getAmount();
                if ($before($payment->getPaidOn())) {
                    $opening += $payment->getAmount();
                } else {
                    $payments[] = ['date' => $payment->getPaidOn(), 'label' => sprintf('Versement — %s', $call->getReference() ?? $call->getLabel()), 'amount' => $payment->getAmount(), 'payment' => $payment];
                }
            }
            // A call shows in the period it was issued, and after it as long as something is left to pay.
            $remaining = max(0, $call->getAmount() - $paidByEnd);
            if ($call->getIssuedOn()->format('Y-m-d') <= $to->format('Y-m-d') && ($in($call->getIssuedOn()) || $remaining > 0)) {
                $callRows[] = ['call' => $call, 'paid' => $paidByEnd, 'remaining' => $remaining];
            }
        }

        $byDate = fn (array $a, array $b) => $a['date'] <=> $b['date'];
        usort($income, $byDate);
        usort($expenses, $byDate);
        usort($payments, $byDate);
        usort($callRows, fn (array $a, array $b) => $a['call']->getIssuedOn() <=> $b['call']->getIssuedOn());

        return new self($ownership, $holder, $ownership->shareOf($holder, $to), $from, $to, $opening, $income, $expenses, $callRows, $payments, array_values(array_unique($warnings)));
    }

    public function getIncome(): int { return array_sum(array_column($this->incomeLines, 'part')); }
    public function getExpenses(): int { return array_sum(array_column($this->expenseLines, 'part')); }
    public function getPaid(): int { return array_sum(array_column($this->paymentLines, 'amount')); }
    public function getClosing(): int { return $this->opening + $this->getIncome() - $this->getExpenses() + $this->getPaid(); }
    /** What is left to pay on their calls at the period's end. */
    public function getDue(): int { return array_sum(array_column($this->calls, 'remaining')); }
    public function getName(): string { return $this->share?->getName() ?? $this->holder; }

    /** "2026-T3", "2026-10", "2026": a file name's part. */
    public function getPeriodKey(): string
    {
        return Period::key($this->from, $this->to);
    }

    public function getPeriodLabel(): string
    {
        return Period::label($this->from, $this->to);
    }
}
