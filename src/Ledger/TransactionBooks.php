<?php

namespace Base\Estate\Ledger;

use Base\Estate\Entity\Sale;
use Base\Estate\Entity\SaleMandate;
use Base\Estate\Enum\FeePayer;
use Base\Estate\Service\Books;
use Base\Ledger\Entity\Account;
use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\Entry;
use Base\Ledger\Entity\EntryLine;
use Base\Ledger\Entity\Party;
use Base\Ledger\Entity\Tax;
use Base\Ledger\Enum\AccountType;
use Base\Ledger\Enum\PartyKind;
use Base\Ledger\Enum\TaxKind;
use Base\Ledger\Model\EntryDraft;
use Base\Ledger\Repository\AccountRepository;
use Base\Ledger\Repository\TaxRepository;
use Base\Ledger\Service\Lettering;
use Base\Ledger\Service\Parties;
use Base\Ledger\Service\Posting;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Sales for third parties in the agency's books (loi Hoguet: no fee is
 * due, nor received, before the sale is concluded - art. 6 of the loi
 * n° 70-9, art. 74 of its décret). Every line carries the dimensions
 * transaction=<sale mandate id> and unit=<id> when the property is one of
 * estate's homes.
 *
 * - Until the acte authentique: nothing is the agency's income, nothing
 *   is posted for the fee.
 * - The acte signed: the fee invoiced to whoever the mandate names -
 *   411 + the seller or the buyer (PartyKind::CUSTOMER) all taxes
 *   included / 7062 « honoraires de transaction » before tax and 44571
 *   its VAT (EntryDraft::taxed(), when the book is liable); its invoice
 *   number (FH-2026-0001) as the piece, so the notary's transfer is
 *   matched by the bank reconciliation.
 * - The deposit (séquestre) is the notary's to hold, usually: recorded on
 *   the sale, not posted. When the mandate says the agency holds it: in
 *   on the escrow account at the compromis (512x / 467 + the buyer,
 *   PartyKind::OTHER), out at the acte (to the notary) or at the
 *   cancellation (back to the buyer, or to the seller when forfeited):
 *   467 / 512x, lettered.
 *
 * Each posting carries its source: nothing twice. Only loaded when
 * omnibase/ledger is installed (config/services.php).
 */
class TransactionBooks
{
    public const FEES = '7062';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Posting $posting,
        private readonly Parties $parties,
        private readonly Lettering $lettering,
        private readonly ManagementBooks $managementBooks,
        private readonly ?TaxRepository $taxes = null,
        private readonly ?AccountRepository $accounts = null,
    ) {
    }

    public function bookFor(SaleMandate $mandate): ?Book
    {
        $id = $mandate->getOrganisation()->getBookId();

        return $id ? $this->entityManager->find(Book::class, $id) : null;
    }

    /** The fee's VAT code in the agency's book, when the book is liable and the code collects VAT; null otherwise. */
    public function feeTax(SaleMandate $mandate): ?Tax
    {
        $book = $this->bookFor($mandate);
        if (null === $book || null === $mandate->getTaxCode() || !$book->getVatRegime()->isLiable()) {
            return null;
        }
        $tax = $this->taxes?->findOneByCode($book, $mandate->getTaxCode());

        return null !== $tax && TaxKind::COLLECTED === $tax->getKind() ? $tax : null;
    }

    /** The fee invoiced at the acte: 411 the payer / 7062 + 44571. */
    public function feeInvoiced(Sale $sale): void
    {
        $mandate = $sale->getMandate();
        if ($sale->getFeeTotal() <= 0 || !$book = $this->bookFor($mandate)) {
            return;
        }
        $this->ensureAccount($book, self::FEES, 'Honoraires de transaction', AccountType::INCOME);
        $party = $this->payerOf($book, $sale);
        $dimensions = self::dimensions($mandate);
        $draft = new EntryDraft($book, 'VT', $sale->getDeedSignedOn() ?? new \DateTimeImmutable('today'),
            sprintf('Honoraires de transaction %s — %s', $mandate->getNumber(), $mandate->getPropertyName()), 'estate:transaction:fee:'.$sale->getId(), $sale->getInvoiceNumber());
        $draft->line($party->getAccountNumber(), $sale->getFeeTotal(), 0, $party, null, $dimensions);
        $tax = $sale->getFeeVat() > 0 ? $this->feeTax($mandate) : null;
        if (null !== $tax) {
            $draft->taxed(self::FEES, 0, $sale->getFeeAmount(), $tax, false, null, null, $dimensions);
        } else {
            $draft->line(self::FEES, 0, $sale->getFeeTotal(), null, null, $dimensions);
        }
        $this->posting->post($draft);
    }

    /** The deposit the agency holds, in on its escrow account at the compromis: 512x / 467 the buyer. */
    public function depositReceived(Sale $sale): void
    {
        $mandate = $sale->getMandate();
        if ($sale->getDeposit() <= 0 || !$sale->isDepositHeldByAgency() || !$book = $this->bookFor($mandate)) {
            return;
        }
        $party = $this->depositorOf($book, $sale);
        $dimensions = self::dimensions($mandate);
        $draft = new EntryDraft($book, 'BQ', $sale->getCompromiseSignedOn() ?? new \DateTimeImmutable('today'),
            sprintf('Séquestre %s — %s', $mandate->getNumber(), $sale->getBuyer()->getName()), 'estate:transaction:deposit:'.$sale->getId(), $mandate->getNumber());
        $draft->line($this->managementBooks->cashAccount($book), $sale->getDeposit(), 0, null, null, $dimensions);
        $draft->line($party->getAccountNumber(), 0, $sale->getDeposit(), $party, null, $dimensions);
        $this->posting->post($draft);
    }

    /**
     * The deposit out of the escrow account: to the notary at the acte, back
     * to the buyer or to the seller (forfeited) at a cancellation - 467 the
     * buyer / 512x, lettered.
     */
    public function depositReleased(Sale $sale, \DateTimeImmutable $on, string $label): void
    {
        $mandate = $sale->getMandate();
        if ($sale->getDeposit() <= 0 || !$sale->isDepositHeldByAgency() || !$book = $this->bookFor($mandate)) {
            return;
        }
        $party = $this->depositorOf($book, $sale);
        $dimensions = self::dimensions($mandate);
        $draft = new EntryDraft($book, 'BQ', $on, sprintf('%s — séquestre %s', $label, $mandate->getNumber()), 'estate:transaction:deposit:'.$sale->getId().':out', $mandate->getNumber());
        $draft->line($party->getAccountNumber(), $sale->getDeposit(), 0, $party, null, $dimensions);
        $draft->line($this->managementBooks->cashAccount($book), 0, $sale->getDeposit(), null, null, $dimensions);
        $this->posting->post($draft);

        $lines = [];
        foreach (['estate:transaction:deposit:'.$sale->getId(), 'estate:transaction:deposit:'.$sale->getId().':out'] as $source) {
            $entry = $this->entityManager->getRepository(Entry::class)->findOneBy(['book' => $book, 'source' => $source]);
            foreach ($entry?->getLines() ?? [] as $line) {
                if ($line->getAccount()->getNumber() === $party->getAccountNumber() && !$line->isLettered()) {
                    $lines[] = $line;
                }
            }
        }
        if (\count($lines) >= 2 && 0 === array_sum(array_map(fn (EntryLine $l) => $l->getNet(), $lines))) {
            $this->lettering->letter($lines);
            $this->entityManager->flush();
        }
    }

    /** Who owes the fee, as a customer: 411 + seller:<id> (the first seller) or buyer:<id>. */
    public function payerOf(Book $book, Sale $sale): Party
    {
        if (FeePayer::BUYER === ($sale->getFeePayer() ?? $sale->getMandate()->getFeePayer())) {
            $buyer = $sale->getBuyer();

            return $this->parties->partyFor($book, PartyKind::CUSTOMER, 'buyer:'.$buyer->getId(), sprintf('%s (acquéreur)', $buyer->getName()));
        }
        $seller = $sale->getMandate()->getSellers()->first() ?: null;

        return null === $seller
            ? $this->parties->partyFor($book, PartyKind::CUSTOMER, 'seller:mandate:'.$sale->getMandate()->getId(), sprintf('Vendeur %s', $sale->getMandate()->getNumber()))
            : $this->parties->partyFor($book, PartyKind::CUSTOMER, 'seller:'.$seller->getId(), sprintf('%s (vendeur)', $sale->getMandate()->getSellersSummary()));
    }

    /** The buyer's current account for the deposit the agency holds for them: 467 + B<id>. */
    public function depositorOf(Book $book, Sale $sale): Party
    {
        $buyer = $sale->getBuyer();

        return $this->parties->partyFor($book, PartyKind::OTHER, 'B'.$buyer->getId(), sprintf('%s (acquéreur, séquestre)', $buyer->getName()));
    }

    /** @return array<string, string> transaction=<sale mandate id>, and unit=<id> for one of estate's homes */
    public static function dimensions(SaleMandate $mandate): array
    {
        $id = $mandate->getId();

        return (null === $id ? [] : [TransactionDimensionLabeler::AXIS => (string) $id]) + Books::unit($mandate->getUnit());
    }

    private function ensureAccount(Book $book, string $number, string $label, AccountType $type): void
    {
        if (null === $this->accounts || null !== $this->accounts->findOneByNumber($book, $number)) {
            return;
        }
        $this->entityManager->persist(new Account($book, $number, $label, $type));
        $this->entityManager->flush();
    }
}
