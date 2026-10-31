<?php

namespace Tests\Base\Estate\Service;

use Base\Estate\Entity\Buyer;
use Base\Estate\Entity\Offer;
use Base\Estate\Entity\SaleMandate;
use Base\Estate\Enum\UnitKind;
use Base\Estate\Ledger\TransactionBooks;
use Base\Estate\Repository\SaleMandateRepository;
use Base\Estate\Repository\SaleRepository;
use Base\Estate\Service\Activities;
use Base\Estate\Service\ActivityLog;
use Base\Estate\Service\Transactions;
use Base\Ledger\Entity\BankAccount;
use Base\Ledger\Service\Lettering;

/**
 * The sales' services over records kept in memory, on ManagementTestCase's
 * (an in-memory entity manager, the postings kept as drafts, Agence
 * Kléber's book). Hélène Roth sells her flat in Strasbourg (VT-2026-0001,
 * 310 000 € asked, 5 % TTC paid by the seller, the notary holding the
 * deposit); Lucas Martin looks for a flat of 3 rooms at 320 000 €.
 */
abstract class TransactionTestCase extends ManagementTestCase
{
    protected SaleMandate $saleMandate;
    protected Buyer $buyer;
    protected ?string $lastNumber = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->records = [];
        $this->saleMandate = self::withId((new SaleMandate($this->agency, new \DateTimeImmutable('2026-09-01')))->setNumber('VT-2026-0001')->addSeller($this->owner)
            ->setAddress('4 rue de l\'Orangerie')->setPostcode('67000')->setCity('Strasbourg')->setKind(UnitKind::APARTMENT)->setSurface(72)->setRooms(3)
            ->setPrice(31000000)->setEndsOn(new \DateTimeImmutable('2026-12-31'))->setFeeRate(500), 9);
        $this->buyer = self::withId((new Buyer('Lucas Martin'))->setEmail('lucas@example.org')->setBudget(32000000)->setKind(UnitKind::APARTMENT)->setMinRooms(3)->setArea('Strasbourg, Schiltigheim'), 7);
        $this->records[SaleMandate::class] = [$this->saleMandate];
        $this->records[Buyer::class] = [$this->buyer];
    }

    protected function transactions(array $activities = ['transaction'], bool $books = true, ?BankAccount $escrow = null): Transactions
    {
        $entityManager = $this->entityManager();
        $mandates = $this->createStub(SaleMandateRepository::class);
        $mandates->method('lastNumber')->willReturnCallback(fn () => $this->lastNumber);
        $mandates->method('ending')->willReturnCallback(fn (\DateTimeImmutable $today, \DateTimeImmutable $until) => array_values(array_filter($this->records[SaleMandate::class] ?? [],
            fn (SaleMandate $m) => $m->getEndsOn() >= $today && $m->getEndsOn() <= $until)));
        $sales = $this->createStub(SaleRepository::class);
        $sales->method('lastInvoiceNumber')->willReturn(null);
        $transactionBooks = $books
            ? new TransactionBooks($entityManager, $this->posting(), $this->parties(), $this->createStub(Lettering::class), $this->books($entityManager, $escrow), $this->taxes(), $this->accounts())
            : null;

        return new Transactions($entityManager, new Activities($activities), $mandates, $sales, new ActivityLog($entityManager), $transactionBooks);
    }

    /** An offer on the flat, saved. */
    protected function offer(int $amount = 30000000, string $madeOn = '2026-10-05', ?Buyer $buyer = null, ?SaleMandate $mandate = null): Offer
    {
        $offer = self::withId(new Offer($mandate ?? $this->saleMandate, $buyer ?? $this->buyer, $amount, new \DateTimeImmutable($madeOn)), ++$this->ids);
        $this->records[Offer::class][] = $offer;

        return $offer;
    }

    /** @return list<string> the sources posted, in order */
    protected function sources(): array
    {
        return array_map(fn ($draft) => $draft->source, $this->drafts);
    }
}
