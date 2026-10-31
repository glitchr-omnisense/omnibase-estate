<?php

namespace Tests\Base\Estate\Model;

use Base\Estate\Entity\Lease;
use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\Ownership;
use Base\Estate\Entity\OwnershipCall;
use Base\Estate\Entity\OwnershipInvoice;
use Base\Estate\Entity\OwnershipPayment;
use Base\Estate\Entity\OwnershipShare;
use Base\Estate\Entity\Payment;
use Base\Estate\Entity\RentCall;
use Base\Estate\Entity\Unit;
use Base\Estate\Enum\PaymentMethod;
use Base\Estate\Model\OwnershipPeriod;
use Base\Estate\Model\OwnershipStatement;
use PHPUnit\Framework\TestCase;

/**
 * A co-owner's statement for a quarter in which their share changes: the
 * rents before it in the opening balance at the old share, those in it at
 * the share of their day, their part of an invoice, their payment, what is
 * left to pay on their call.
 */
final class OwnershipStatementTest extends TestCase
{
    public function testTheBalancesFollowTheSharesInForce(): void
    {
        $organisation = new Organisation('Meyers & Associée');
        $unit = new Unit($organisation, 'Maison de la Robertsau');
        $ownership = (new Ownership($organisation, 'Indivision Robertsau'))->addUnit($unit);
        $start = new \DateTimeImmutable('2026-01-01');
        $sale = new \DateTimeImmutable('2026-07-01');
        (new OwnershipShare($ownership, 'Meyers & Associée', 1, 2, $start))->setCompany(true);
        $before = (new OwnershipShare($ownership, 'Claire Hoffmann', 1, 2, $start))->setEmail('claire@example.org')->setEndsOn($sale);
        $after = (new OwnershipShare($ownership, 'Claire Hoffmann', 1, 4, $sale))->setEmail('claire@example.org');
        (new OwnershipShare($ownership, 'Antoine Schaller', 1, 4, $sale))->setEmail('antoine@example.org');

        $lease = new Lease($unit, 'MA-2021-001', new \DateTimeImmutable('2021-09-01'));
        $rent = fn (string $month, string $paidOn) => new Payment((new RentCall($lease, new \DateTimeImmutable($month)))->setRent(148000)->setCharges(12000), 160000, PaymentMethod::TRANSFER, new \DateTimeImmutable($paidOn));
        $rents = [$rent('2026-06-01', '2026-05-31'), $rent('2026-08-01', '2026-07-31'), $rent('2026-10-01', '2026-09-30')];

        $invoice = (new OwnershipInvoice($ownership, 'Réfection de la toiture', 600000, new \DateTimeImmutable('2026-08-10')))->setSupplierName('Toitures Fritsch');
        $invoice->markPosted($ownership->allocate(600000, $invoice->getDate()), 600000);
        $call = (new OwnershipCall($after, 'Réfection de la toiture', 150000, new \DateTimeImmutable('2026-09-10'), new \DateTimeImmutable('2026-08-10')))->setInvoice($invoice)->setReference('AF-2026-0001');
        new OwnershipPayment($call, 100000, new \DateTimeImmutable('2026-09-01'));
        new OwnershipPayment($call, 50000, new \DateTimeImmutable('2026-10-03'));
        $old = (new OwnershipCall($before, 'Provision travaux', 30000, new \DateTimeImmutable('2026-03-31'), new \DateTimeImmutable('2026-03-01')))->setReference('AF-2026-0000');
        new OwnershipPayment($old, 30000, new \DateTimeImmutable('2026-03-20'));

        [$from, $to] = OwnershipPeriod::parse('2026-Q3');
        $statement = OwnershipStatement::of($ownership, 'email:claire@example.org', $from, $to, [$invoice], [$call, $old], $rents);

        self::assertSame(80000 + 30000, $statement->opening, 'May\'s rent at 1/2, and the provision she paid in March');
        self::assertSame([40000, 40000], array_column($statement->incomeLines, 'part'), 'July and September at 1/4');
        self::assertSame(80000, $statement->getIncome());
        self::assertSame(150000, $statement->getExpenses(), 'her quarter of the roof');
        self::assertSame(100000, $statement->getPaid(), 'October\'s payment is the next quarter\'s');
        self::assertSame(110000 + 80000 - 150000 + 100000, $statement->getClosing());
        self::assertSame(50000, $statement->getDue());
        self::assertSame(['AF-2026-0001'], array_map(fn (array $row) => $row['call']->getReference(), $statement->calls), 'the provision paid before the quarter is not listed');
        self::assertSame('3e trimestre 2026', $statement->getPeriodLabel());
        self::assertSame('2026-Q3', $statement->getPeriodKey());

        // The next quarter opens where this one closed.
        [$from, $to] = OwnershipPeriod::parse('2026-Q4');
        $next = OwnershipStatement::of($ownership, 'email:claire@example.org', $from, $to, [$invoice], [$call, $old], $rents);
        self::assertSame($statement->getClosing(), $next->opening);
        self::assertSame(50000, $next->getPaid());
        self::assertSame(0, $next->getDue());
    }
}
