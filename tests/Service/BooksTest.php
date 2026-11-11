<?php

namespace Tests\Base\Estate\Service;

use Base\Estate\Entity\Contractor;
use Base\Estate\Entity\Lease;
use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\RentCall;
use Base\Estate\Entity\Unit;
use Base\Estate\Entity\WorkOrder;
use Base\Estate\Service\Books;
use Base\Estate\Enum\LeaseKind;
use Base\Estate\Service\Management;
use Base\Ledger\Entity\Account;
use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\Tax;
use Base\Ledger\Enum\TaxKind;
use Base\Ledger\Enum\VatRegime;
use Base\Ledger\Repository\TaxRepository;
use Base\Ledger\Entity\Entry;
use Base\Ledger\Entity\Party;
use Base\Ledger\Enum\PartyKind;
use Base\Ledger\Model\EntryDraft;
use Base\Ledger\Service\Lettering;
use Base\Ledger\Service\Parties;
use Base\Ledger\Service\Posting;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * What estate posts to the books carries the unit on every line (unit=<id>),
 * so the ledger gives each home's result.
 */
final class BooksTest extends TestCase
{
    /** @var list<EntryDraft> */
    private array $drafts = [];

    protected function setUp(): void
    {
        if (!class_exists(Posting::class)) {
            self::markTestSkipped('omnibase/ledger is needed.');
        }
    }

    private function books(Book $book, ?Tax $tax = null, ?Management $management = null): Books
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('find')->willReturn($book);
        $posting = $this->createStub(Posting::class);
        $posting->method('post')->willReturnCallback(function (EntryDraft $draft) use ($book) {
            $this->drafts[] = $draft;

            return new Entry($book, new \Base\Ledger\Entity\Journal($book, $draft->journal, $draft->journal), $draft->date, $draft->label, $draft->source);
        });
        $parties = $this->createStub(Parties::class);
        $parties->method('partyFor')->willReturnCallback(fn (Book $book, string $kind, string $reference, string $name) => new Party($book, PartyKind::from($kind), $name, PartyKind::from($kind)->collective().'X', 'X'));

        $taxes = $this->createStub(TaxRepository::class);
        $taxes->method('findOneByCode')->willReturnCallback(fn (Book $b, string $code) => $tax && $tax->getCode() === $code ? $tax : null);

        return new Books($entityManager, $posting, $parties, $this->createStub(Lettering::class), $management, $taxes);
    }

    private static function withId(object $entity, int $id): object
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);

        return $entity;
    }

    public function testARentCallAndAWorkInvoiceArePostedWithTheirUnit(): void
    {
        $book = new Book('Meyers & Associée');
        $organisation = (new Organisation('Meyers & Associée'))->setBookId(1);
        $unit = self::withId(new Unit($organisation, 'Appartement Krutenau'), 12);
        $call = (new RentCall(new Lease($unit, 'MA-2026-001', new \DateTimeImmutable('2026-01-01')), new \DateTimeImmutable('2026-10-01')))->setRent(85000)->setCharges(6000);
        $order = (new WorkOrder($unit, 'Fuite'))->setContractor(self::withId(new Contractor($organisation, 'Plomberie Ott'), 4))->setInvoiceAmount(30000);

        $books = $this->books($book);
        $books->rentCalled($call);
        $books->workInvoiced($order);

        self::assertCount(2, $this->drafts);
        foreach ($this->drafts as $draft) {
            self::assertSame(array_fill(0, \count($draft->getLines()), ['unit' => '12']), array_column($draft->getLines(), 'dimensions'), $draft->label);
        }
        self::assertSame(['411X', '706', '708'], array_column($this->drafts[0]->getLines(), 'account'));
    }

    public function testARentCalledWithVatPostsItsBaseAndItsVat(): void
    {
        $book = (new Book('Meyers & Associée'))->setVatRegime(VatRegime::NORMAL_MONTHLY);
        $tax = new Tax($book, 'TVA20', 'TVA collectée 20 %', 2000, TaxKind::COLLECTED, new Account($book, '44571', 'TVA collectée'));
        $organisation = (new Organisation('Meyers & Associée'))->setBookId(1);
        $unit = self::withId(new Unit($organisation, 'Boutique Krutenau'), 14);
        $lease = (new Lease($unit, 'MA-2026-C01', new \DateTimeImmutable('2026-01-01')))->setKind(LeaseKind::COMMERCIAL)->setIndexName('ILC')->setTaxCode('tva20')->setRent(200000)->setCharges(15000);
        $books = $this->books($book, $tax);

        // The rent's VAT only, 20 % of 2 000 €; the charges too when the lease says so.
        self::assertSame(['vat' => 40000, 'code' => 'TVA20'], $books->vatOf($lease, 200000, 15000));
        self::assertSame(['vat' => 43000, 'code' => 'TVA20'], $books->vatOf((clone $lease)->setTaxCharges(true), 200000, 15000));

        $call = (new RentCall($lease, new \DateTimeImmutable('2026-10-01')))->setVat(40000, 'TVA20');
        self::assertSame(255000, $call->getTotal());
        $books->rentCalled($call);
        $lines = $this->drafts[0]->getLines();
        self::assertSame(['411X', '706', '44571', '708'], array_column($lines, 'account'));
        self::assertSame([255000, 0, 0, 0], array_column($lines, 'debit'));
        self::assertSame([0, 200000, 40000, 15000], array_column($lines, 'credit'));
        self::assertSame(200000, $lines[2]['taxBase']);
    }

    public function testNoVatWithoutALiableBookOrAKnownCode(): void
    {
        $book = new Book('Meyers & Associée');
        $tax = new Tax($book, 'TVA20', 'TVA collectée 20 %', 2000, TaxKind::COLLECTED, new Account($book, '44571', 'TVA collectée'));
        $organisation = (new Organisation('Meyers & Associée'))->setBookId(1);
        $lease = (new Lease(self::withId(new Unit($organisation, 'Boutique'), 15), 'C-2'))->setTaxCode('TVA20');

        // The company not liable (VIES does not know it): its book's regime says so.
        self::assertNull($this->books($book, $tax)->vatOf($lease, 200000, 0));
        $book->setVatRegime(VatRegime::NORMAL_MONTHLY);
        self::assertNull($this->books($book, $tax)->vatOf((clone $lease)->setTaxCode('TVA55'), 200000, 0));
        self::assertNull($this->books($book, $tax)->vatOf((clone $lease)->setTaxCode(null), 200000, 0));
        self::assertNotNull($this->books($book, $tax)->vatOf($lease, 200000, 0));
    }
}
