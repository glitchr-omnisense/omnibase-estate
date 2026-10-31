<?php

namespace Tests\Base\Estate\Service;

use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\Ownership;
use Base\Estate\Entity\OwnershipCall;
use Base\Estate\Entity\OwnershipInvoice;
use Base\Estate\Entity\OwnershipShare;
use Base\Estate\Entity\Unit;
use Base\Estate\Enum\SplitMode;
use Base\Estate\Ledger\OwnershipBooks;
use Base\Estate\Repository\OwnershipCallRepository;
use Base\Estate\Repository\OwnershipRepository;
use Base\Estate\Service\Activities;
use Base\Estate\Service\ActivityLog;
use Base\Estate\Service\EstateMailer;
use Base\Estate\Service\Ownerships;
use Base\Estate\Service\Receipts;
use Base\Ledger\Entity\Account;
use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\Entry;
use Base\Ledger\Entity\Party;
use Base\Ledger\Entity\Tax;
use Base\Ledger\Enum\AccountType;
use Base\Ledger\Enum\PartyKind;
use Base\Ledger\Enum\TaxKind;
use Base\Ledger\Enum\VatRegime;
use Base\Ledger\Model\EntryDraft;
use Base\Ledger\Repository\AccountRepository;
use Base\Ledger\Repository\TaxRepository;
use Base\Ledger\Service\Lettering;
use Base\Ledger\Service\Parties;
use Base\Ledger\Service\Posting;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Twig\Environment;

/**
 * A shared invoice divided among the co-owners and posted through the
 * public service: the expense with its VAT, the supplier, each co-owner's
 * call for funds - every line marked with the ownership and the home.
 */
final class OwnershipsTest extends TestCase
{
    /** @var list<EntryDraft> */
    private array $drafts = [];
    private int $ids = 0;

    protected function setUp(): void
    {
        if (!class_exists(Posting::class)) {
            self::markTestSkipped('omnibase/ledger is needed.');
        }
    }

    private static function withId(object $entity, int $id): object
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);

        return $entity;
    }

    private function service(Book $book): Ownerships
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('find')->willReturn($book);
        // Saved: a call gets its id, as the database would give it.
        $entityManager->method('persist')->willReturnCallback(function (object $entity) {
            if ($entity instanceof OwnershipCall && null === $entity->getId()) {
                self::withId($entity, ++$this->ids);
            }
        });
        $posting = $this->createStub(Posting::class);
        $posting->method('post')->willReturnCallback(function (EntryDraft $draft) use ($book) {
            $this->drafts[] = $draft;

            return new Entry($book, new \Base\Ledger\Entity\Journal($book, $draft->journal, $draft->journal), $draft->date, $draft->label, $draft->source);
        });
        $parties = $this->createStub(Parties::class);
        $parties->method('partyFor')->willReturnCallback(fn (Book $book, PartyKind $kind, string $reference, string $name) => new Party($book, $kind, $name, $kind->collective().strtoupper(preg_replace('/\W/', '', $reference)), $reference));
        $taxes = $this->createStub(TaxRepository::class);
        $taxes->method('findOneByCode')->willReturn(new Tax($book, 'A20', 'TVA déductible 20 %', 2000, TaxKind::DEDUCTIBLE, new Account($book, '44566', 'TVA déductible', AccountType::ASSET)));
        $accounts = $this->createStub(AccountRepository::class);
        $accounts->method('findOneByNumber')->willReturn(new Account($book, '7551', 'Quote-part', AccountType::INCOME));
        $books = new OwnershipBooks($entityManager, $posting, $parties, $this->createStub(Lettering::class), $taxes, $accounts);

        return new Ownerships($entityManager, new Activities(['assets', 'ownership']), $this->createStub(OwnershipRepository::class), $this->createStub(OwnershipCallRepository::class),
            new EstateMailer($this->createStub(MailerInterface::class)), $this->createStub(Receipts::class), $this->createStub(Environment::class), new ActivityLog($entityManager), $books);
    }

    /** Meyers & Associée 1/2, Claire Hoffmann 1/4, Antoine Schaller 1/4; the roof of the house, 6 000 €. */
    private static function invoice(): OwnershipInvoice
    {
        $organisation = (new Organisation('Meyers & Associée'))->setBookId(1);
        $ownership = self::withId(new Ownership($organisation, 'Indivision Robertsau'), 3);
        $unit = self::withId(new Unit($organisation, 'Maison de la Robertsau'), 12);
        $ownership->addUnit($unit);
        $start = new \DateTimeImmutable('2026-01-01');
        self::withId((new OwnershipShare($ownership, 'Meyers & Associée', 1, 2, $start))->setCompany(true), 1);
        self::withId((new OwnershipShare($ownership, 'Claire Hoffmann', 1, 4, $start))->setEmail('claire@example.org'), 2);
        self::withId((new OwnershipShare($ownership, 'Antoine Schaller', 1, 4, $start))->setEmail('antoine@example.org'), 3);

        return self::withId((new OwnershipInvoice($ownership, 'Réfection de la toiture', 600000, new \DateTimeImmutable('2026-10-05')))
            ->setSupplierName('Toitures Fritsch')->setNumber('F-2026-311')->setTaxCode('A20')->setUnit($unit), 7);
    }

    /** @return array<string, array{0: int, 1: int}> account => [debit, credit] */
    private static function lines(EntryDraft $draft): array
    {
        $lines = [];
        foreach ($draft->getLines() as $line) {
            $lines[$line['account']] = [$line['debit'], $line['credit']];
        }

        return $lines;
    }

    public function testASixThousandEuroInvoiceIsSplitByTheSharesAndPostedWithItsVatAndDimensions(): void
    {
        $book = (new Book('Meyers & Associée'))->setVatRegime(VatRegime::NORMAL_MONTHLY);
        $invoice = self::invoice();
        $calls = $this->service($book)->post($invoice, new \DateTimeImmutable('2026-11-05'), false);

        // The book gets the VAT back: the co-owners share the 5 000 € before tax.
        self::assertSame(500000, $invoice->getSharedAmount());
        self::assertSame(['company' => 250000, 'email:claire@example.org' => 125000, 'email:antoine@example.org' => 125000], $invoice->getParts());
        self::assertSame(['Claire Hoffmann', 'Antoine Schaller'], array_map(fn (OwnershipCall $c) => $c->getShare()->getName(), $calls), 'the company does not call itself');
        self::assertSame([125000, 125000], array_map(fn (OwnershipCall $c) => $c->getAmount(), $calls));
        self::assertSame(['AF-'.date('Y').'-0001', 'AF-'.date('Y').'-0002'], array_map(fn (OwnershipCall $c) => $c->getReference(), $calls));

        self::assertCount(3, $this->drafts);
        [$expense, $first, $second] = $this->drafts;
        self::assertSame('estate:ownership:invoice:7', $expense->source);
        self::assertSame(['615' => [500000, 0], '44566' => [100000, 0], '401SUPPLIERTOITURESFRITSCH' => [0, 600000]], self::lines($expense));
        $vat = array_values(array_filter($expense->getLines(), fn ($l) => '44566' === $l['account']))[0];
        self::assertSame(500000, $vat['taxBase']);
        self::assertSame(['467IND32' => [125000, 0], '7551' => [0, 125000]], self::lines($first));
        self::assertSame(['467IND33' => [125000, 0], '7551' => [0, 125000]], self::lines($second));
        self::assertSame($calls[0]->getReference(), $first->piece, 'the reference a transfer quotes: the reconciliation finds the call by it');
        foreach ($this->drafts as $draft) {
            foreach ($draft->getLines() as $line) {
                self::assertSame(['ownership' => '3', 'unit' => '12'], $line['dimensions'], $draft->label.' '.$line['account']);
            }
        }
    }

    public function testABookOutsideVatSharesTheAmountAllTaxesIncluded(): void
    {
        $invoice = self::invoice();
        $calls = $this->service(new Book('Meyers & Associée'))->post($invoice, null, false);

        self::assertSame([150000, 150000], array_map(fn (OwnershipCall $c) => $c->getAmount(), $calls));
        self::assertSame(['615' => [600000, 0], '401SUPPLIERTOITURESFRITSCH' => [0, 600000]], self::lines($this->drafts[0]), 'no VAT line: the 6 000 € is the expense');
    }

    public function testASplitSetByHand(): void
    {
        $book = new Book('Meyers & Associée');
        $invoice = self::invoice()->setSplitMode(SplitMode::AMOUNTS)->setSplit(['company' => 400000, 'email:claire@example.org' => 150000, 'email:antoine@example.org' => 40000]);
        $service = $this->service($book);

        $preview = $service->split($invoice);
        self::assertNotSame([], $preview['errors'], '5 900 € typed for 6 000 €');
        try {
            $service->post($invoice, null, false);
            self::fail('A split that does not add up is not posted.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $this->drafts);
        }

        $invoice->setSplit(['company' => 400000, 'email:claire@example.org' => 150000, 'email:antoine@example.org' => 50000]);
        $calls = $service->post($invoice, null, false);
        self::assertSame([150000, 50000], array_map(fn (OwnershipCall $c) => $c->getAmount(), $calls));
        self::assertSame(400000, $invoice->getPart('company'));

        $percent = self::invoice()->setSplitMode(SplitMode::PERCENTAGES)->setSplit(['company' => 6000, 'email:claire@example.org' => 3000, 'email:antoine@example.org' => 1000]);
        self::assertSame([360000, 180000, 60000], array_column($service->split($percent)['parts'], 'amount'));
    }
}
