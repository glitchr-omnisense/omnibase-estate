<?php

namespace Tests\Base\Estate\Service;

use Base\Estate\Entity\Buyer;
use Base\Estate\Entity\Sale;
use Base\Estate\Entity\SaleEvent;
use Base\Estate\Entity\SaleMandate;
use Base\Estate\Enum\FeeBasis;
use Base\Estate\Enum\FeePayer;
use Base\Estate\Enum\OfferStatus;
use Base\Estate\Enum\SaleStep;
use Base\Estate\Enum\UnitKind;
use Base\Estate\Model\WithdrawalPeriod;
use Base\Ledger\Enum\VatRegime;
use Symfony\Component\HttpFoundation\File\File;

/**
 * A sale for a third party through the public services: the mandate's
 * number, an offer accepted and the French steps one at a time (a step
 * skipped refused, the withdrawal period's end), the fee invoiced at the
 * acte with its VAT to whoever the mandate names - nothing before -, a
 * cancellation and the deposit the agency holds, a buyer matched, and the
 * activity off: nothing.
 */
final class TransactionsTest extends TransactionTestCase
{
    public function testAMandateIsNumberedInTheRegister(): void
    {
        $transactions = $this->transactions();
        $this->lastNumber = 'VT-2026-0007';

        $first = new SaleMandate($this->agency, new \DateTimeImmutable('2026-11-02'));
        $second = new SaleMandate($this->agency, new \DateTimeImmutable('2026-11-03'));
        self::assertSame('VT-2026-0008', $transactions->number($first), 'after the last one of its year');
        self::assertSame('VT-2026-0009', $transactions->number($second), 'the next one in the same request, before any flush');
        self::assertSame('VT-2026-0008', $transactions->number($first), 'a number is given once');

        $this->lastNumber = null;
        self::assertSame('VT-2027-0001', $transactions->number(new SaleMandate($this->agency, new \DateTimeImmutable('2027-01-04'))), 'a new year starts again');
        self::assertSame('VT-2026-0001', $transactions->number($this->saleMandate), 'a number typed is kept');
    }

    public function testAnOfferAcceptedAndTheStepsInOrder(): void
    {
        $transactions = $this->transactions();
        $offer = $this->offer();
        $transactions->counter($offer, 30500000, new \DateTimeImmutable('2026-10-06'));
        self::assertSame(OfferStatus::COUNTERED, $offer->getStatus());

        $sale = $transactions->accept($offer, new \DateTimeImmutable('2026-10-08'));

        self::assertSame(OfferStatus::ACCEPTED, $offer->getStatus());
        self::assertSame([SaleStep::ACCEPTED, 30500000], [$sale->getStep(), $sale->getPrice()], 'at the counter-offer\'s price');
        self::assertSame($sale, $transactions->saleOf($this->saleMandate));
        try {
            $transactions->accept($this->offer(30200000, '2026-10-09', self::withId(new Buyer('Inès Roux'), 8)));
            self::fail('a mandate under offer takes no other');
        } catch (\InvalidArgumentException) {
        }
        try {
            $transactions->advance($sale, SaleStep::WITHDRAWAL, new \DateTimeImmutable('2026-10-20'));
            self::fail('a step skipped is refused');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Compromis signé', $e->getMessage(), 'it says which step comes next');
        }
        self::assertSame(SaleStep::ACCEPTED, $sale->getStep(), 'nothing moved');

        $file = tempnam(sys_get_temp_dir(), 'compromis');
        file_put_contents($file, '%PDF-1.4');
        $event = $transactions->advance($sale, SaleStep::COMPROMISE, new \DateTimeImmutable('2026-10-20'),
            ['notary' => 'Me Weber', 'deposit' => 1525000, 'notifiedOn' => new \DateTimeImmutable('2026-10-21'), 'loanDeadline' => new \DateTimeImmutable('2026-12-04'), 'deedPlannedOn' => new \DateTimeImmutable('2027-01-15')],
            'Signé à l\'agence', new File($file), 'Compromis.pdf');
        unlink($file);
        self::assertSame([SaleStep::COMPROMISE, 'Me Weber', 1525000, '2026-11-02'], [$sale->getStep(), $sale->getNotary(), $sale->getDeposit(), $sale->getWithdrawalEndsOn()->format('Y-m-d')],
            'notified on Wednesday 21 October: the tenth day is Saturday 31, then Sunday 1 November, a holiday - Monday 2');
        self::assertSame(['Compromis.pdf', true], [$event->getFileName(), $event->hasFile()]);
        self::assertSame(['step' => SaleStep::WITHDRAWAL, 'date' => $sale->getWithdrawalEndsOn()], $sale->nextDate());

        try {
            $transactions->advance($sale, SaleStep::CONDITIONS, new \DateTimeImmutable('2026-11-20'));
            self::fail('the withdrawal period is a step of its own');
        } catch (\InvalidArgumentException) {
        }
        try {
            $transactions->advance($sale, SaleStep::WITHDRAWAL, new \DateTimeImmutable('2026-11-02'));
            self::fail('the buyer may still withdraw on its last day');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('02/11/2026', $e->getMessage());
        }
        $transactions->advance($sale, SaleStep::WITHDRAWAL, new \DateTimeImmutable('2026-11-03'));
        $transactions->advance($sale, SaleStep::CONDITIONS, new \DateTimeImmutable('2026-11-27'), note: 'Offre de prêt reçue');
        $transactions->advance($sale, SaleStep::DEED, new \DateTimeImmutable('2027-01-15'));
        $transactions->advance($sale, SaleStep::CLOSED, new \DateTimeImmutable('2027-01-20'));

        self::assertSame(SaleStep::CLOSED, $sale->getStep());
        self::assertSame(['accepted', 'compromise', 'withdrawal', 'conditions', 'deed', 'closed'], array_map(fn (SaleEvent $e) => $e->getStep()->value, $sale->getEvents()->toArray()), 'each step dated on the timeline');
        self::assertSame(['2026-10-08', '2026-10-20', '2026-11-03', '2026-11-27', '2027-01-15', '2027-01-20'], array_map(fn (SaleStep $s) => $sale->dateOf($s)->format('Y-m-d'), SaleStep::PATH));
        self::assertSame([], $transactions->salesInProgress(), 'closed: no longer in progress');
        $this->expectException(\InvalidArgumentException::class);
        $transactions->advance($sale, SaleStep::CLOSED);
    }

    public function testTheWithdrawalPeriodsEnd(): void
    {
        self::assertSame('2026-11-09', WithdrawalPeriod::end(new \DateTimeImmutable('2026-10-30'))->format('Y-m-d'), 'from the next day, ten days: a Monday');
        self::assertSame('2026-11-02', WithdrawalPeriod::end(new \DateTimeImmutable('2026-10-21'))->format('Y-m-d'), 'a Saturday, then a Sunday and All Saints\' Day: the next working day');
        self::assertSame('2026-11-12', WithdrawalPeriod::end(new \DateTimeImmutable('2026-11-01'))->format('Y-m-d'), 'the 11th of November is a holiday');
        self::assertSame('2026-04-05', WithdrawalPeriod::easter(2026)->format('Y-m-d'));
        self::assertSame('2027-03-28', WithdrawalPeriod::easter(2027)->format('Y-m-d'));
        self::assertSame('2026-04-07', WithdrawalPeriod::end(new \DateTimeImmutable('2026-03-27'))->format('Y-m-d'), 'Easter Monday');
        self::assertSame('2026-05-15', WithdrawalPeriod::end(new \DateTimeImmutable('2026-05-04'))->format('Y-m-d'), 'Ascension Day, 14 May');
        self::assertSame('2026-04-03', WithdrawalPeriod::end(new \DateTimeImmutable('2026-03-24'))->format('Y-m-d'), 'Good Friday is worked in most of France');
        self::assertSame('2026-04-07', WithdrawalPeriod::end(new \DateTimeImmutable('2026-03-24'), true)->format('Y-m-d'), 'not in Alsace-Moselle');
    }

    public function testTheFeeIsPostedAtTheDeedWithItsVatByThePartyTheMandateNames(): void
    {
        $this->book->setVatRegime(VatRegime::NORMAL_MONTHLY);
        $transactions = $this->transactions();
        $sale = $transactions->accept($this->offer(30000000), new \DateTimeImmutable('2026-10-08'));
        $transactions->advance($sale, SaleStep::COMPROMISE, new \DateTimeImmutable('2026-10-20'), ['deposit' => 1500000]);
        $transactions->advance($sale, SaleStep::WITHDRAWAL, new \DateTimeImmutable('2026-11-03'));
        $transactions->advance($sale, SaleStep::CONDITIONS, new \DateTimeImmutable('2026-11-27'));
        self::assertSame([], $this->drafts, 'nothing is the agency\'s before the acte, and the notary\'s deposit is not posted');
        self::assertSame(0, $sale->getFeeTotal());

        $transactions->advance($sale, SaleStep::DEED, new \DateTimeImmutable('2027-01-15'));

        // 5 % TTC of 300 000 € = 15 000 €, of which 2 500 € of VAT.
        self::assertSame(['FH-2027-0001', 1500000, 1250000, 250000, 'V20', FeePayer::SELLER], [$sale->getInvoiceNumber(), $sale->getFeeTotal(), $sale->getFeeAmount(), $sale->getFeeVat(), $sale->getFeeTaxCode(), $sale->getFeePayer()]);
        $draft = $this->draft('estate:transaction:fee:'.$sale->getId());
        self::assertNotNull($draft);
        self::assertSame(['VT', '2027-01-15', 'FH-2027-0001'], [$draft->journal, $draft->date->format('Y-m-d'), $draft->piece]);
        self::assertSame(['411SELLER5' => [1500000, 0], '7062' => [0, 1250000], '44571' => [0, 250000]], self::lines($draft), 'the seller owes it: their customer account');
        foreach ($draft->getLines() as $line) {
            self::assertSame(['transaction' => '9'], $line['dimensions']);
        }
        self::assertCount(1, $this->drafts, 'the fee alone');

        // Paid by the buyer, a fixed fee, a book outside VAT.
        $this->book->setVatRegime(VatRegime::NONE);
        $other = self::withId((new SaleMandate($this->agency, new \DateTimeImmutable('2026-10-01')))->setNumber('VT-2026-0002')->addSeller($this->owner)->setPrice(18000000)
            ->setFeeBasis(FeeBasis::FIXED)->setFeeAmount(800000)->setFeePayer(FeePayer::BUYER), 10);
        $this->records[SaleMandate::class][] = $other;
        $sale = $transactions->accept($this->offer(17500000, '2026-10-10', mandate: $other), new \DateTimeImmutable('2026-10-12'));
        foreach ([SaleStep::COMPROMISE->value => '2026-10-20', SaleStep::WITHDRAWAL->value => '2026-11-03', SaleStep::CONDITIONS->value => '2026-11-20', SaleStep::DEED->value => '2026-12-18'] as $step => $on) {
            $transactions->advance($sale, SaleStep::from($step), new \DateTimeImmutable($on));
        }
        self::assertSame(['411BUYER7' => [800000, 0], '7062' => [0, 800000]], self::lines($this->draft('estate:transaction:fee:'.$sale->getId())), 'the buyer owes it, no VAT outside the regime');
        self::assertSame(['FH-2026-0001', 0, null], [$sale->getInvoiceNumber(), $sale->getFeeVat(), $sale->getFeeTaxCode()]);
    }

    public function testACancellationGivesTheDepositBack(): void
    {
        $this->saleMandate->setDepositHeldByAgency(true);
        $transactions = $this->transactions(escrow: $this->escrow());
        $sale = $transactions->accept($this->offer(), new \DateTimeImmutable('2026-10-08'));
        $transactions->advance($sale, SaleStep::COMPROMISE, new \DateTimeImmutable('2026-10-20'), ['deposit' => 1500000, 'notifiedOn' => new \DateTimeImmutable('2026-10-21')]);

        $in = $this->draft('estate:transaction:deposit:'.$sale->getId());
        self::assertSame(['5121' => [1500000, 0], '467B7' => [0, 1500000]], self::lines($in), 'the agency holds it: on its escrow account, owed to the buyer');

        try {
            $transactions->cancel($sale, '  ');
            self::fail('a cancellation says why');
        } catch (\InvalidArgumentException) {
        }
        $transactions->cancel($sale, 'Rétractation de l\'acquéreur', new \DateTimeImmutable('2026-10-27'));

        self::assertSame([SaleStep::CANCELLED, 'Rétractation de l\'acquéreur', '2026-10-27', false], [$sale->getStep(), $sale->getCancelReason(), $sale->getCancelledOn()->format('Y-m-d'), $sale->isDepositForfeited()]);
        $out = $this->draft('estate:transaction:deposit:'.$sale->getId().':out');
        self::assertSame(['467B7' => [1500000, 0], '5121' => [0, 1500000]], self::lines($out), 'given back from the escrow account');
        self::assertStringContainsString('Restitué', $out->label);
        self::assertNull($this->draft('estate:transaction:fee:'.$sale->getId()), 'no fee on a sale not concluded');
        self::assertNull($transactions->saleOf($this->saleMandate), 'the mandate is free again');

        $again = $transactions->accept($this->offer(29800000, '2026-10-29', self::withId(new Buyer('Inès Roux'), 8)), new \DateTimeImmutable('2026-10-30'));
        self::assertSame(SaleStep::ACCEPTED, $again->getStep(), 'another offer may be accepted');
        foreach ([SaleStep::COMPROMISE->value => '2026-11-05', SaleStep::WITHDRAWAL->value => '2026-11-17', SaleStep::CONDITIONS->value => '2026-12-01', SaleStep::DEED->value => '2027-01-08'] as $step => $on) {
            $transactions->advance($again, SaleStep::from($step), new \DateTimeImmutable($on));
        }
        $this->expectException(\InvalidArgumentException::class);
        $transactions->cancel($again, 'Trop tard');
    }

    public function testABuyerIsMatchedAgainstTheMandates(): void
    {
        $transactions = $this->transactions();
        $mandate = fn (int $id, int $price, UnitKind $kind, ?string $city, int $rooms, string $until = '2026-12-31') => $this->records[SaleMandate::class][] = self::withId(
            (new SaleMandate($this->agency, new \DateTimeImmutable('2026-09-01')))->setNumber('VT-2026-00'.$id)->setPrice($price)->setKind($kind)->setCity($city)->setRooms($rooms)->setEndsOn(new \DateTimeImmutable($until)), $id);
        $negotiate = $mandate(20, 33500000, UnitKind::APARTMENT, 'Schiltigheim', 4);
        $mandate(21, 34000000, UnitKind::APARTMENT, 'Strasbourg', 3);       // more than 5 % above
        $mandate(22, 25000000, UnitKind::HOUSE, 'Strasbourg', 5);           // a house
        $mandate(23, 25000000, UnitKind::APARTMENT, 'Colmar', 3);           // elsewhere
        $mandate(24, 25000000, UnitKind::APARTMENT, 'Strasbourg', 2);       // too small
        $mandate(25, 25000000, UnitKind::APARTMENT, 'Strasbourg', 3, '2026-10-01'); // ended
        $sold = $mandate(26, 26000000, UnitKind::APARTMENT, 'Strasbourg', 3);
        $transactions->accept($this->offer(26000000, '2026-10-01', self::withId(new Buyer('Inès Roux'), 8), $sold));
        $unsaid = $mandate(27, 28000000, UnitKind::APARTMENT, null, 3);      // no town: not ruled out

        $matches = $transactions->matches($this->buyer, new \DateTimeImmutable('2026-10-31'));

        self::assertSame([$unsaid, $this->saleMandate, $negotiate], array_column($matches, 'mandate'), 'the cheapest first');
        self::assertSame(['dans le budget', 'le type cherché', '3 pièces', 'Strasbourg'], $matches[1]['reasons']);
        self::assertSame('jusqu\'à 5 % au-dessus du budget : à négocier', $matches[2]['reasons'][0]);
    }

    public function testTheActivityOffNothing(): void
    {
        $transactions = $this->transactions(['assets', 'ownership']);
        $offer = $this->offer();

        self::assertFalse($transactions->isActive());
        self::assertSame([], $transactions->matches($this->buyer, new \DateTimeImmutable('2026-10-31')));
        self::assertSame([], $transactions->offersWaiting());
        self::assertSame([], $transactions->salesInProgress());
        self::assertSame([], $transactions->ending(new \DateTimeImmutable('2026-12-01'), new \DateTimeImmutable('2027-01-01')));
        self::assertSame(0, $transactions->feesOfQuarter(new \DateTimeImmutable('2026-10-31'))['total']);
        try {
            $transactions->accept($offer);
            self::fail('nothing changes while the site does not run transaction');
        } catch (\LogicException $e) {
            self::assertNotInstanceOf(\InvalidArgumentException::class, $e);
        }
        self::assertSame(OfferStatus::MADE, $offer->getStatus());
        self::assertArrayNotHasKey(Sale::class, $this->records);
        self::assertSame([], $this->drafts);
    }
}
