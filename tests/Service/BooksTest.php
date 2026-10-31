<?php

namespace Tests\Base\Estate\Service;

use Base\Estate\Entity\Contractor;
use Base\Estate\Entity\Lease;
use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\RentCall;
use Base\Estate\Entity\Unit;
use Base\Estate\Entity\WorkOrder;
use Base\Estate\Service\Books;
use Base\Ledger\Entity\Book;
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

    private function books(Book $book): Books
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

        return new Books($entityManager, $posting, $parties, $this->createStub(Lettering::class));
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
}
