<?php

namespace Tests\Base\Estate\Service;

use Base\Estate\Entity\Lease;
use Base\Estate\Entity\Mandate;
use Base\Estate\Entity\Mandator;
use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\Payment;
use Base\Estate\Entity\RentCall;
use Base\Estate\Entity\Unit;
use Base\Estate\Enum\PaymentMethod;
use Base\Estate\Ledger\ManagementBooks;
use Base\Estate\Repository\MandatePayoutRepository;
use Base\Estate\Repository\MandateRepository;
use Base\Estate\Repository\RentCallRepository;
use Base\Estate\Service\Activities;
use Base\Estate\Service\ActivityLog;
use Base\Estate\Service\Books;
use Base\Estate\Service\EstateMailer;
use Base\Estate\Service\Management;
use Base\Estate\Service\Receipts;
use Base\Ledger\Entity\Account;
use Base\Ledger\Entity\BankAccount;
use Base\Ledger\Entity\BankConnection;
use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\Entry;
use Base\Ledger\Entity\Journal;
use Base\Ledger\Entity\Party;
use Base\Ledger\Entity\Tax;
use Base\Ledger\Enum\AccountType;
use Base\Ledger\Enum\PartyKind;
use Base\Ledger\Enum\TaxKind;
use Base\Ledger\Model\EntryDraft;
use Base\Ledger\Repository\AccountRepository;
use Base\Ledger\Repository\BankAccountRepository;
use Base\Ledger\Repository\TaxRepository;
use Base\Ledger\Service\Lettering;
use Base\Ledger\Service\Parties;
use Base\Ledger\Service\Posting;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Twig\Environment;

/**
 * The management's services over records kept in memory: what is persisted
 * gets an id and is found again by its repository (findBy / findOneBy on its
 * getters), the postings are kept as drafts. An agency, Agence Kléber, manages
 * Hélène Roth's flat (unit 12) under mandate MG-2026-0001 since January:
 * 7 % before tax, VAT 20 %.
 */
abstract class ManagementTestCase extends TestCase
{
    /** @var list<EntryDraft> */
    protected array $drafts = [];
    /** @var array<class-string, list<object>> */
    protected array $records = [];
    /** @var list<array{0: string, 1: string}> to, subject */
    protected array $sent = [];
    protected int $ids = 100;
    protected Book $book;
    protected Organisation $agency;
    protected Mandator $owner;
    protected Mandate $mandate;
    protected Unit $unit;
    protected Lease $lease;

    protected function setUp(): void
    {
        if (!class_exists(Posting::class)) {
            self::markTestSkipped('omnibase/ledger is needed.');
        }
        $this->book = new Book('Agence Kléber');
        $this->agency = (new Organisation('Agence Kléber'))->setBookId(1)->setEmail('gestion@kleber.example')->setIban('FR7630006000011234567890189');
        $this->owner = self::withId((new Mandator('Hélène Roth'))->setEmail('helene@example.org')->setIban('FR76 1027 8060 0000 0202 7280 196'), 5);
        $this->unit = self::withId(new Unit($this->agency, 'Appartement Orangerie'), 12);
        $this->lease = new Lease($this->unit, 'MA-2026-012', new \DateTimeImmutable('2025-06-01'));
        $this->mandate = self::withId((new Mandate($this->agency, $this->owner, new \DateTimeImmutable('2026-01-01')))->setNumber('MG-2026-0001')->setFeeRate(700)->addUnit($this->unit), 3);
        $this->records[Mandate::class] = [$this->mandate];
    }

    protected static function withId(object $entity, int $id): object
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);

        return $entity;
    }

    protected function management(array $activities = ['management'], bool $books = true, ?BankAccount $escrow = null): Management
    {
        $entityManager = $this->entityManager();
        $mandates = $this->createStub(MandateRepository::class);
        $mandates->method('findForUnit')->willReturnCallback(fn (Unit $unit, \DateTimeInterface $on) => array_values(array_filter($this->records[Mandate::class] ?? [], fn (Mandate $m) => $m->hasUnit($unit) && $m->covers($on)))[0] ?? null);
        $mandates->method('inForce')->willReturnCallback(fn (\DateTimeImmutable $from, \DateTimeImmutable $to) => array_values(array_filter($this->records[Mandate::class] ?? [], fn (Mandate $m) => $m->overlaps($from, $to))));
        $mandates->method('findAll')->willReturnCallback(fn () => $this->records[Mandate::class] ?? []);
        $payouts = $this->createStub(MandatePayoutRepository::class);
        $payouts->method('findUnpaid')->willReturnCallback(fn () => array_values(array_filter($this->records[\Base\Estate\Entity\MandatePayout::class] ?? [], fn ($p) => !$p->isPaid())));
        $calls = $this->createStub(RentCallRepository::class);
        $calls->method('forUnits')->willReturnCallback(fn (array $units) => array_values(array_filter($this->records[RentCall::class] ?? [], fn (RentCall $c) => \in_array($c->getLease()->getUnit(), $units, true))));
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function ($email) {
            $this->sent[] = [$email->getTo()[0]->getAddress(), (string) $email->getSubject()];
        });

        return new Management($entityManager, new Activities($activities), $mandates, $payouts, $calls,
            new EstateMailer($mailer), $this->createStub(Receipts::class), $this->createStub(Environment::class), new ActivityLog($entityManager),
            $books ? $this->books($entityManager, $escrow) : null);
    }

    protected function booksService(Management $management): Books
    {
        return new Books($this->entityManager(), $this->posting(), $this->parties(), $this->createStub(Lettering::class), $management);
    }

    protected function books(EntityManagerInterface $entityManager, ?BankAccount $escrow = null): ManagementBooks
    {
        $taxes = $this->createStub(TaxRepository::class);
        $taxes->method('findOneByCode')->willReturnCallback(fn (Book $book, string $code) => 'V20' === $code ? new Tax($book, 'V20', 'TVA collectée 20 %', 2000, TaxKind::COLLECTED, new Account($book, '44571', 'TVA collectée', AccountType::LIABILITY)) : null);
        $accounts = $this->createStub(AccountRepository::class);
        $accounts->method('findOneByNumber')->willReturnCallback(fn (Book $book, string $number) => new Account($book, $number, $number));
        $bankAccounts = $this->createStub(BankAccountRepository::class);
        $bankAccounts->method('escrowFor')->willReturn($escrow);

        return new ManagementBooks($entityManager, $this->posting(), $this->parties(), $this->createStub(Lettering::class), $taxes, $accounts, $bankAccounts);
    }

    /** The agency's escrow account at the bank: 5121, its IBAN the payouts' debtor. */
    protected function escrow(): BankAccount
    {
        return (new BankAccount(new BankConnection($this->book, 'files'), 'kleber-sequestre', 'Compte séquestre', new Account($this->book, '5121', 'Banque séquestre'), new Journal($this->book, 'BQ2', 'Séquestre'), 'FR7614508000300000000000184'))->setEscrow(true);
    }

    protected function entityManager(): EntityManagerInterface
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('find')->willReturnCallback(fn (string $class, $id) => Book::class === $class ? $this->book : (array_values(array_filter($this->records[$class] ?? [], fn ($r) => $r->getId() === (int) $id))[0] ?? null));
        $entityManager->method('persist')->willReturnCallback(function (object $entity) {
            if (property_exists($entity, 'id') && null === $entity->getId()) {
                self::withId($entity, ++$this->ids);
            }
            if (!\in_array($entity, $this->records[$entity::class] ?? [], true)) {
                $this->records[$entity::class][] = $entity;
            }
        });
        $entityManager->method('remove')->willReturnCallback(function (object $entity) {
            $this->records[$entity::class] = array_values(array_filter($this->records[$entity::class] ?? [], fn ($r) => $r !== $entity));
        });
        $entityManager->method('getRepository')->willReturnCallback(function (string $class) {
            $repository = $this->createStub(EntityRepository::class);
            $matching = fn (array $criteria) => array_values(array_filter($this->records[$class] ?? [], function (object $record) use ($criteria) {
                foreach ($criteria as $field => $value) {
                    $getter = method_exists($record, 'get'.ucfirst($field)) ? 'get'.ucfirst($field) : 'is'.ucfirst($field);
                    if (!method_exists($record, $getter) || $record->$getter() !== $value) {
                        return false;
                    }
                }

                return true;
            }));
            $repository->method('findBy')->willReturnCallback($matching);
            $repository->method('findOneBy')->willReturnCallback(fn (array $criteria) => $matching($criteria)[0] ?? null);
            $repository->method('findAll')->willReturnCallback(fn () => $this->records[$class] ?? []);

            return $repository;
        });

        return $entityManager;
    }

    protected function posting(): Posting
    {
        $posting = $this->createStub(Posting::class);
        $posting->method('post')->willReturnCallback(function (EntryDraft $draft) {
            $this->drafts[] = $draft;

            return new Entry($draft->book, new Journal($draft->book, $draft->journal, $draft->journal), $draft->date, $draft->label, $draft->source, $draft->piece);
        });

        return $posting;
    }

    protected function parties(): Parties
    {
        $parties = $this->createStub(Parties::class);
        $parties->method('partyFor')->willReturnCallback(fn (Book $book, PartyKind|string $kind, string $reference, string $name) => new Party($book, $kind = $kind instanceof PartyKind ? $kind : PartyKind::from($kind), $name, $kind->collective().strtoupper(preg_replace('/\W/', '', $reference)), $reference));

        return $parties;
    }

    /** A rent call of the flat, saved, paid $paid on $paidOn (0: none). */
    protected function rentCall(string $month, int $rent = 85000, int $charges = 5000, int $paid = 0, ?string $paidOn = null): RentCall
    {
        $call = self::withId((new RentCall($this->lease, new \DateTimeImmutable($month.'-01')))->setRent($rent)->setCharges($charges), ++$this->ids);
        $this->records[RentCall::class][] = $call;
        if ($paid > 0) {
            $this->pay($call, $paid, $paidOn ?? $month.'-05');
        }

        return $call;
    }

    protected function pay(RentCall $call, int $amount, string $paidOn): Payment
    {
        $payment = self::withId(new Payment($call, $amount, PaymentMethod::TRANSFER, new \DateTimeImmutable($paidOn)), ++$this->ids);
        $call->addPayment($payment);
        $this->records[Payment::class][] = $payment;

        return $payment;
    }

    /** @return array<string, array{0: int, 1: int}> account => [debit, credit] */
    protected static function lines(EntryDraft $draft): array
    {
        $lines = [];
        foreach ($draft->getLines() as $line) {
            $lines[$line['account']] = [($lines[$line['account']][0] ?? 0) + $line['debit'], ($lines[$line['account']][1] ?? 0) + $line['credit']];
        }

        return $lines;
    }

    protected function draft(string $source): ?EntryDraft
    {
        foreach ($this->drafts as $draft) {
            if ($draft->source === $source) {
                return $draft;
            }
        }

        return null;
    }
}
