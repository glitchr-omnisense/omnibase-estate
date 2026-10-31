<?php

namespace Tests\Base\Estate\Service;

use Base\Estate\Entity\Contractor;
use Base\Estate\Entity\MandateExpense;
use Base\Estate\Entity\MandateFee;
use Base\Estate\Entity\WorkOrder;
use Base\Estate\Enum\WorkOrderStatus;
use Base\Ledger\Enum\VatRegime;

/**
 * A home managed for its owner, through the public services: its rent
 * called and collected is the owner's (4672, then their 4671 account, with
 * the owner and the home on every line), the agency's fee and its VAT are
 * taken from it, a work order's invoice is charged to them - and, the
 * activity off, none of it.
 */
final class ManagementTest extends ManagementTestCase
{
    public function testARentCollectedGoesToTheOwnersAccountWithItsDimensions(): void
    {
        $this->book->setVatRegime(VatRegime::NORMAL_MONTHLY);
        $management = $this->management();
        $payment = $this->pay($this->rentCall('2026-10'), 90000, '2026-10-04');

        $fee = $management->rentReceived($payment);

        $collected = $this->draft('estate:management:rent:'.$payment->getId());
        self::assertNotNull($collected, 'the rent collected is posted');
        self::assertSame(['4672' => [90000, 0], '4671M5' => [0, 90000]], self::lines($collected), 'not the agency\'s income: the owner\'s account');
        self::assertNull($collected->piece, 'the rent\'s reference leads the reconciliation to the tenant\'s notice alone');
        foreach ($this->drafts as $draft) {
            foreach ($draft->getLines() as $line) {
                self::assertSame(['owner' => '5', 'unit' => '12'], $line['dimensions'], $draft->label.' '.$line['account']);
            }
        }
        self::assertSame(1, \count($this->records[MandateFee::class]), 'the fee is kept as charged');
        self::assertSame($fee, $management->rentReceived($payment), 'once per payment');
        self::assertCount(2, $this->drafts, 'nothing posted twice');
    }

    public function testTheFeeAndItsVatAreTakenFromTheOwnersAccount(): void
    {
        $this->book->setVatRegime(VatRegime::NORMAL_MONTHLY);
        $management = $this->management();
        $payment = $this->pay($this->rentCall('2026-10'), 90000, '2026-10-04');

        $fee = $management->rentReceived($payment);

        // 7 % of 900,00 € = 63,00 € before tax, 12,60 € of VAT.
        self::assertSame([90000, 700, 6300, 1260, 7560], [$fee->getBase(), $fee->getRate(), $fee->getAmount(), $fee->getVat(), $fee->getTotal()]);
        self::assertSame('V20', $fee->getTaxCode());
        $draft = $this->draft('estate:management:fee:'.$fee->getId());
        self::assertSame(['4671M5' => [7560, 0], '7061' => [0, 6300], '44571' => [0, 1260]], self::lines($draft));
        $vat = array_values(array_filter($draft->getLines(), fn ($l) => '44571' === $l['account']))[0];
        self::assertSame(6300, $vat['taxBase'], 'the VAT line knows its base: the VAT return reads it');
        self::assertSame([6300, 1260, 'V20'], $management->fee($this->mandate, 90000));
        self::assertSame(3150, $management->fee($this->mandate, 45000)[0], '7 % of 450,00 € is 31,50 €');
        self::assertSame(1, $management->fee($this->mandate->setFeeRate(650), 15)[0], '6,5 % of 0,15 € rounds half up to 0,01 €');
    }

    public function testABookOutsideVatChargesTheFeeWithoutVat(): void
    {
        $management = $this->management();
        $fee = $management->rentReceived($this->pay($this->rentCall('2026-10'), 90000, '2026-10-04'));

        self::assertSame([6300, 0, null], [$fee->getAmount(), $fee->getVat(), $fee->getTaxCode()]);
        self::assertSame(['4671M5' => [6300, 0], '7061' => [0, 6300]], self::lines($this->draft('estate:management:fee:'.$fee->getId())));
    }

    public function testTheRentCallOfAManagedHomeIsTheOwnersNotTheAgencysIncome(): void
    {
        $management = $this->management();
        $books = $this->booksService($management);
        $call = $this->rentCall('2026-11');

        $books->rentCalled($call);

        self::assertCount(1, $this->drafts);
        self::assertSame('estate:rent:'.$call->getReference(), $this->drafts[0]->source, 'the same source as any rent: the bank settlement finds it');
        self::assertSame($call->getReference(), $this->drafts[0]->piece);
        self::assertSame(['411LEASEMA2026012' => [90000, 0], '4672' => [0, 90000]], self::lines($this->drafts[0]), 'no 706, no 708');

        // A home the agency's own (no mandate on it then): its rent is its income, as before.
        $this->mandate->setStartsOn(new \DateTimeImmutable('2027-01-01'));
        $this->drafts = [];
        $books->rentCalled($this->rentCall('2026-12'));
        self::assertSame(['411X', '706', '708'], array_map(fn ($l) => '411LEASEMA2026012' === $l['account'] ? '411X' : $l['account'], $this->drafts[0]->getLines()));
    }

    public function testAManagedHomesMoneyLandsOnTheEscrowAccount(): void
    {
        $management = $this->management(escrow: $this->escrow());
        $books = $this->booksService($management);
        $payment = $this->pay($this->rentCall('2026-10'), 90000, '2026-10-04');

        $books->paymentReceived($payment);

        self::assertSame(['5121' => [90000, 0], '411LEASEMA2026012' => [0, 90000]], self::lines($this->drafts[0]), 'the owners\' money on the compte séquestre');
        self::assertSame('5121', $management->cashAccount($this->unit, new \DateTimeImmutable('2026-10-04')));
    }

    public function testAWorkOrdersInvoiceIsChargedToTheOwner(): void
    {
        $management = $this->management();
        $books = $this->booksService($management);
        $order = self::withId((new WorkOrder($this->unit, 'Chauffe-eau'))->setContractor(self::withId(new Contractor($this->agency, 'Plomberie Ott'), 4))->setInvoiceAmount(42000)->setInvoiceNumber('F-118'), 31);
        $order->setStatus(WorkOrderStatus::CLOSED)->setClosedAt(new \DateTimeImmutable('2026-10-20'));

        $books->workInvoiced($order);
        self::assertSame([], $this->drafts, 'not the agency\'s expense: 615 is not posted');
        $expense = $management->workInvoiced($order);

        self::assertInstanceOf(MandateExpense::class, $expense);
        self::assertSame([42000, 'F-118', $this->unit], [$expense->getAmount(), $expense->getNumber(), $expense->getUnit()]);
        self::assertTrue($expense->isPosted());
        self::assertSame(['4671M5' => [42000, 0], '401CONTRACTOR4' => [0, 42000]], self::lines($this->draft('estate:management:expense:'.$expense->getId())), 'all taxes included: the owner bears the VAT');
        self::assertSame($expense, $management->workInvoiced($order), 'once per work order');
        self::assertCount(1, $this->drafts);
    }

    public function testAnExpenseEnteredByHandIsChargedOnce(): void
    {
        $management = $this->management();
        $expense = (new MandateExpense($this->mandate, 'Appel de charges T4', 31000, new \DateTimeImmutable('2026-10-01')))->setSupplierName('Syndic Foncia Alsace')->setUnit($this->unit);

        $management->postExpense($expense);
        $management->postExpense($expense);

        self::assertCount(1, $this->drafts);
        self::assertSame(['4671M5' => [31000, 0], '401SUPPLIERSYNDICFONCIAALSACE' => [0, 31000]], self::lines($this->drafts[0]));
    }

    public function testAPaymentTakenBackTakesItsFeeWithIt(): void
    {
        $management = $this->management();
        $payment = $this->pay($this->rentCall('2026-10'), 90000, '2026-10-04');
        $management->rentReceived($payment);

        $management->rentCancelled($payment);

        self::assertSame([], $this->records[MandateFee::class], 'the fee is gone with its payment');
    }

    public function testTheActivityOffNothing(): void
    {
        $management = $this->management(['assets', 'ownership']);
        $books = $this->booksService($management);
        $call = $this->rentCall('2026-10');
        $payment = $this->pay($call, 90000, '2026-10-04');

        self::assertNull($management->mandateFor($this->unit, new \DateTimeImmutable('2026-10-04')));
        self::assertNull($management->rentReceived($payment));
        self::assertFalse($management->isManaged($this->unit, new \DateTimeImmutable('2026-10-04')));
        self::assertSame([], $management->payoutsDue(new \DateTimeImmutable('2026-10-31')));
        self::assertSame([], $management->statementsToSend(new \DateTimeImmutable('2026-11-02')));
        self::assertSame([], $management->mandatorsOf((new \ReflectionClass(\Base\Entity\User::class))->newInstanceWithoutConstructor()));
        self::assertSame(0, $management->catchUp());
        self::assertArrayNotHasKey(MandateFee::class, $this->records);

        $books->rentCalled($call);
        self::assertSame(['706', '708'], \array_slice(array_column($this->drafts[0]->getLines(), 'account'), 1), 'the rent is the company\'s income, as before');
    }
}
