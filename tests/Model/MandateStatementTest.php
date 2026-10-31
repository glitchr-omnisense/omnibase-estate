<?php

namespace Tests\Base\Estate\Model;

use Base\Estate\Entity\Lease;
use Base\Estate\Entity\Mandate;
use Base\Estate\Entity\MandateExpense;
use Base\Estate\Entity\MandateFee;
use Base\Estate\Entity\MandatePayout;
use Base\Estate\Entity\Mandator;
use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\Payment;
use Base\Estate\Entity\RentCall;
use Base\Estate\Entity\Unit;
use Base\Estate\Enum\PaymentMethod;
use Base\Estate\Enum\StatementFrequency;
use Base\Estate\Model\MandateStatement;
use Base\Estate\Model\Period;
use PHPUnit\Framework\TestCase;

/**
 * An owner's statement for October 2026: the opening balance from what came
 * before (since the mandate's start only), the rents called and collected,
 * the fees, an expense, a payout, the closing balance, what a payout would
 * be less the reserve, and the rent a tenant still owes - listed, not
 * counted.
 */
final class MandateStatementTest extends TestCase
{
    private static function withId(object $entity, int $id): object
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);

        return $entity;
    }

    private static function call(Lease $lease, string $month, array $payments = []): RentCall
    {
        static $ids = 0;
        $call = self::withId((new RentCall($lease, new \DateTimeImmutable($month.'-01')))->setRent(85000)->setCharges(5000), ++$ids);
        foreach ($payments as $on => $amount) {
            $call->addPayment(self::withId(new Payment($call, $amount, PaymentMethod::TRANSFER, new \DateTimeImmutable($on)), ++$ids));
        }

        return $call;
    }

    public function testAMonthsStatementBalances(): void
    {
        $agency = new Organisation('Agence Kléber');
        $unit = self::withId(new Unit($agency, 'Appartement Orangerie'), 12);
        $lease = new Lease($unit, 'MA-2026-012', new \DateTimeImmutable('2025-06-01'));
        $mandate = (new Mandate($agency, new Mandator('Hélène Roth'), new \DateTimeImmutable('2026-01-01')))->addUnit($unit)->setReserve(20000);
        $calls = [
            self::call($lease, '2025-12', ['2025-12-05' => 90000]),                         // before the mandate: not the agency's to account for
            self::call($lease, '2026-08', ['2026-08-06' => 50000]),                         // 400,00 € still owed
            self::call($lease, '2026-09', ['2026-09-04' => 90000]),
            self::call($lease, '2026-10', ['2026-10-05' => 60000, '2026-10-20' => 30000]),
            self::call($lease, '2026-11'),                                                  // after the period
        ];
        $fees = [
            new MandateFee($mandate, new \DateTimeImmutable('2026-08-06'), 'Loyer 08/2026', 50000, 700, 3500, 700, 'V20'),
            new MandateFee($mandate, new \DateTimeImmutable('2026-09-04'), 'Loyer 09/2026', 90000, 700, 6300, 1260, 'V20'),
            new MandateFee($mandate, new \DateTimeImmutable('2026-10-05'), 'Loyer 10/2026', 60000, 700, 4200, 840, 'V20'),
            new MandateFee($mandate, new \DateTimeImmutable('2026-10-20'), 'Loyer 10/2026', 30000, 700, 2100, 420, 'V20'),
        ];
        $expense = (new MandateExpense($mandate, 'Chauffe-eau', 42000, new \DateTimeImmutable('2026-10-15')))->setSupplierName('Plomberie Ott')->markPosted();
        $draft = new MandateExpense($mandate, 'Devis non imputé', 99900, new \DateTimeImmutable('2026-10-16'));
        $payout = (new MandatePayout($mandate, new \DateTimeImmutable('2026-09-30'), 128240, 0, 82440, new \DateTimeImmutable('2026-10-01')))->setReference('RV-2026-0004');

        [$from, $to] = Period::parse('2026-10');
        $statement = MandateStatement::of($mandate, $from, $to, $calls, $fees, [$expense, $draft], [$payout]);

        self::assertSame(50000 + 90000 - 4200 - 7560, $statement->opening, 'August and September collected, less their fees; December 2025 is before the mandate');
        self::assertSame([['called' => 90000, 'collected' => 90000]], array_map(fn ($u) => ['called' => $u['called'], 'collected' => $u['collected']], $statement->units));
        self::assertSame(90000, $statement->getCollected());
        self::assertSame(2, \count($statement->rentLines));
        self::assertSame([6300, 1260, 7560], [$statement->getFeesExcludingTax(), $statement->getFeesVat(), $statement->getFees()]);
        self::assertSame(42000, $statement->getExpenses(), 'an expense not posted is not on it');
        self::assertSame(82440, $statement->getPayouts());
        self::assertSame(128240 + 90000 - 7560 - 42000 - 82440, $statement->getClosing());
        self::assertSame(86240 - 20000, $statement->getAvailable(), 'less the reserve');
        self::assertSame(40000, $statement->getUnpaid(), 'August\'s rest, the tenant\'s - not in the balance');
        self::assertSame('2026-10', $statement->getPeriodKey());
        self::assertSame('octobre 2026', $statement->getPeriodLabel());

        // The next month opens on this one's closing balance.
        [$from, $to] = Period::parse('2026-11');
        self::assertSame($statement->getClosing(), MandateStatement::of($mandate, $from, $to, $calls, $fees, [$expense, $draft], [$payout])->opening);
    }

    public function testAMandatesPeriodsAndItsSentStatements(): void
    {
        $mandate = (new Mandate(new Organisation('Agence Kléber'), new Mandator('Hélène Roth'), new \DateTimeImmutable('2026-01-01')))->setEndsOn(new \DateTimeImmutable('2026-12-31'));
        $today = new \DateTimeImmutable('2026-11-02');

        self::assertSame('2026-10', StatementFrequency::MONTHLY->lastClosed($today));
        self::assertSame('2026-Q3', StatementFrequency::QUARTERLY->lastClosed($today));
        self::assertSame('2025', StatementFrequency::YEARLY->lastClosed($today));
        self::assertSame(['2026-10', '2026-09', '2026-08'], StatementFrequency::MONTHLY->recent($today, 3));
        self::assertTrue($mandate->covers(new \DateTimeImmutable('2026-12-31')), 'its last day included');
        self::assertFalse($mandate->covers(new \DateTimeImmutable('2027-01-01')));

        self::assertFalse($mandate->isStatementSent('2026-10'));
        $mandate->markStatementSent('2026-10');
        $mandate->markStatementSent('2026-09');
        self::assertSame('2026-10', $mandate->getStatementSentFor(), 'an older statement sent again leaves the latest');
        self::assertTrue($mandate->isStatementSent('2026-Q3'));
        self::assertFalse($mandate->isStatementSent('2026-Q4'));
        self::assertSame('7 %', $mandate->getFeeLabel());
        self::assertSame('6,5 %', $mandate->setFeePercent('6,5')->getFeeLabel());
        self::assertSame(650, $mandate->getFeeRate());
    }
}
