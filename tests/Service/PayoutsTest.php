<?php

namespace Tests\Base\Estate\Service;

use Base\Estate\Entity\MandateExpense;
use Base\Estate\Entity\MandatePayout;
use Base\Estate\Enum\PayoutStatus;
use Base\Estate\Ledger\ManagementSettlement;
use Base\Ledger\Entity\Account;
use Base\Ledger\Entity\BankLine;
use Base\Ledger\Entity\Entry;
use Base\Ledger\Entity\EntryLine;
use Base\Ledger\Entity\Journal;
use Base\Ledger\Enum\VatRegime;
use Base\Ledger\Event\BankLineReconciled;
use Omnibank\Files\Pain001Writer;

/**
 * An owner's payout at a month's end: their balance less the reserve,
 * issued (4671 / 4678 under its reference), written in a SEPA credit
 * transfer file the ISO 20022 schema accepts, then paid - by hand (4678 /
 * the escrow account) or by the bank line the reconciliation matched.
 */
final class PayoutsTest extends ManagementTestCase
{
    /** October: 900,00 € collected (fee 75,60 € with its VAT), a 100,00 € expense, 200,00 € kept. */
    private function october(): \Base\Estate\Service\Management
    {
        $this->book->setVatRegime(VatRegime::NORMAL_MONTHLY);
        $this->mandate->setReserve(20000);
        $management = $this->management(escrow: $this->escrow());
        $management->rentReceived($this->pay($this->rentCall('2026-10'), 90000, '2026-10-04'));
        $management->postExpense((new MandateExpense($this->mandate, 'Ramonage', 10000, new \DateTimeImmutable('2026-10-12')))->setSupplierName('Ramonage Wolff'));
        $this->drafts = [];

        return $management;
    }

    public function testWhatAnOwnerIsDueAndThePayoutIssued(): void
    {
        $management = $this->october();
        $on = new \DateTimeImmutable('2026-10-31');

        self::assertSame([['balance' => 72440, 'reserve' => 20000, 'amount' => 52440]], array_map(fn ($d) => array_diff_key($d, ['mandate' => 0]), $management->payoutsDue($on)));

        $payout = $management->issuePayout($this->mandate, $on, null, new \DateTimeImmutable('2026-11-02'));
        self::assertSame(['RV-2026-0001', 52440, 72440, 20000, 'FR7610278060000002027280196', PayoutStatus::ISSUED], [$payout->getReference(), $payout->getAmount(), $payout->getBalance(), $payout->getReserve(), $payout->getIban(), $payout->getStatus()]);
        $draft = $this->draft('estate:management:payout:'.$payout->getId());
        self::assertSame(['4671M5' => [52440, 0], '4678' => [0, 52440]], self::lines($draft));
        self::assertSame('RV-2026-0001', $draft->piece, 'the reference the transfer carries back: the reconciliation finds the payout by it');
        self::assertSame(['owner' => '5'], $draft->getLines()[0]['dimensions']);

        self::assertSame([], $management->payoutsDue($on), 'issued once: the balance of the 31st is paid out');
        self::assertSame(20000, $management->balance($this->mandate, new \DateTimeImmutable('2026-11-02')), 'the reserve stays on their account');
        try {
            $management->issuePayout($this->mandate, $on);
            self::fail('Nothing is left to pay out.');
        } catch (\InvalidArgumentException) {
            self::assertCount(1, $this->records[MandatePayout::class]);
        }
    }

    public function testTheSepaFileHoldsAgainstThePain001Schema(): void
    {
        if (!class_exists(Pain001Writer::class)) {
            self::markTestSkipped('omnibank/files is needed.');
        }
        $management = $this->october();
        $payout = $management->issuePayout($this->mandate, new \DateTimeImmutable('2026-10-31'), null, new \DateTimeImmutable('2026-11-02'));

        $xml = $management->sepa([$payout], new \DateTimeImmutable('2026-11-03'), new \DateTimeImmutable('2026-11-02 10:30:00'));

        $document = new \DOMDocument();
        $document->loadXML($xml);
        $schema = \dirname((new \ReflectionClass(Pain001Writer::class))->getFileName()).'/Tests/Fixtures/pain.001.001.03.xsd';
        if (is_file($schema)) {
            libxml_use_internal_errors(true);
            self::assertTrue($document->schemaValidate($schema), 'valid pain.001.001.03: '.implode(' ', array_map(fn ($e) => trim($e->message), libxml_get_errors())));
        }
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('p', Pain001Writer::NAMESPACE);
        $read = fn (string $path) => $xpath->evaluate('string('.$path.')');
        self::assertSame('1', $read('//p:GrpHdr/p:NbOfTxs'));
        self::assertSame('524.40', $read('//p:GrpHdr/p:CtrlSum'));
        self::assertSame('Agence Kleber', $read('//p:PmtInf/p:Dbtr/p:Nm'), 'the agency, in the SEPA character set');
        self::assertSame('FR7614508000300000000000184', $read('//p:DbtrAcct/p:Id/p:IBAN'), 'from the escrow account');
        self::assertSame('2026-11-03', $read('//p:ReqdExctnDt'));
        self::assertSame('RV-2026-0001', $read('//p:CdtTrfTxInf/p:PmtId/p:EndToEndId'));
        self::assertSame('524.40', $read('//p:CdtTrfTxInf/p:Amt/p:InstdAmt'));
        self::assertSame('EUR', $read('//p:CdtTrfTxInf/p:Amt/p:InstdAmt/@Ccy'));
        self::assertSame('Helene Roth', $read('//p:Cdtr/p:Nm'));
        self::assertSame('FR7610278060000002027280196', $read('//p:CdtrAcct/p:Id/p:IBAN'));
        self::assertStringContainsString('RV-2026-0001', $read('//p:RmtInf/p:Ustrd'));
        self::assertSame(PayoutStatus::EXPORTED, $payout->getStatus());

        $this->owner->setIban(null);
        $other = new MandatePayout($this->mandate, new \DateTimeImmutable('2026-11-30'), 1000, 0, 1000);
        $this->expectException(\InvalidArgumentException::class);
        $management->sepa([$other]);
    }

    public function testAPayoutPaidByHandOrByTheBankLine(): void
    {
        $management = $this->october();
        $payout = $management->issuePayout($this->mandate, new \DateTimeImmutable('2026-10-31'), 30000, new \DateTimeImmutable('2026-11-02'));
        $management->markPaid($payout, new \DateTimeImmutable('2026-11-04'), 'VIR RV-2026-0001');

        self::assertTrue($payout->isPaid());
        self::assertSame(['4678' => [30000, 0], '5121' => [0, 30000]], self::lines($this->draft('estate:management:payout:'.$payout->getId().':paid')), 'from the escrow account');

        // The second one comes back on the escrow account's statement, quoting its reference: reconciled, it is paid, nothing posted again.
        $second = $management->issuePayout($this->mandate, new \DateTimeImmutable('2026-10-31'), 22440, new \DateTimeImmutable('2026-11-02'));
        $posted = \count($this->drafts);
        $issued = new Entry($this->book, new Journal($this->book, 'OD', 'OD'), new \DateTimeImmutable('2026-11-02'), 'Reversement', 'estate:management:payout:'.$second->getId(), $second->getReference());
        $line = self::withId(new BankLine($this->escrow(), 'tx-1', new \DateTimeImmutable('2026-11-05'), -22440, 'VIR SEPA EMIS RV-2026-0002 HELENE ROTH'), 77);
        (new ManagementSettlement($this->entityManager(), $management))(new BankLineReconciled($line, $issued, [new EntryLine($issued, new Account($this->book, '4678', '4678'), 0, 22440)]));

        self::assertTrue($second->isPaid());
        self::assertSame(['2026-11-05', 'bank:77'], [$second->getPaidOn()->format('Y-m-d'), $second->getPaidReference()]);
        self::assertCount($posted, $this->drafts, 'the reconciliation posted it');
    }

    public function testAPayoutTakenBackBeforeItIsPaid(): void
    {
        $management = $this->october();
        $payout = $management->issuePayout($this->mandate, new \DateTimeImmutable('2026-10-31'), null, new \DateTimeImmutable('2026-11-02'));

        $management->cancelPayout($payout);

        self::assertSame([], $this->records[MandatePayout::class]);
        self::assertSame(52440, $management->payoutsDue(new \DateTimeImmutable('2026-10-31'))[0]['amount'], 'due again');
    }
}
