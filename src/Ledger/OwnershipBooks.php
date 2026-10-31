<?php

namespace Base\Estate\Ledger;

use Base\Estate\Entity\Ownership;
use Base\Estate\Entity\OwnershipCall;
use Base\Estate\Entity\OwnershipInvoice;
use Base\Estate\Entity\OwnershipPayment;
use Base\Estate\Entity\OwnershipShare;
use Base\Estate\Entity\Payment;
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
 * A shared ownership in its managing organisation's books - the way the
 * PCG has a manager keep operations carried out in common (comptabilité du
 * gérant): every operation in full in classes 6 and 7, each co-owner's
 * part transferred to their own current account, 467 + a reference
 * (PartyKind::OTHER). The managing company's own part, when it is a
 * co-owner, stays in its accounts. Every line carries the dimension
 * ownership=<id> (and unit=<id> when it is about one home): the income
 * statement filtered by ownership is the indivision's.
 *
 * - A shared invoice: 6xx (the base and 44566 when the book is liable to
 *   VAT, the amount all taxes included otherwise) / 401 the supplier.
 * - Its calls for funds, one entry each (its reference as the piece, so
 *   the bank reconciliation matches the co-owner's transfer): 467 the
 *   co-owner / 7551 « quote-part de perte transférée ».
 * - A rent received for one of its homes: 6551 « quote-part de bénéfice
 *   transférée » / 467 each other co-owner, by the shares that day.
 * - A payment recorded by hand: 512 / 467 the co-owner, lettered with its
 *   call once paid in full (a transfer the bank reconciliation matched is
 *   posted and lettered by it).
 *
 * The shared cost is the amount less the VAT the book gets back: what the
 * co-owners bear. Each posting carries its source: nothing twice. Only
 * loaded when omnibase/ledger is installed (config/services.php).
 */
class OwnershipBooks
{
    public const TRANSFERRED_LOSS = '7551';
    public const TRANSFERRED_PROFIT = '6551';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Posting $posting,
        private readonly Parties $parties,
        private readonly Lettering $lettering,
        private readonly ?TaxRepository $taxes = null,
        private readonly ?AccountRepository $accounts = null,
    ) {
    }

    public function bookFor(Ownership $ownership): ?Book
    {
        $id = $ownership->getOrganisation()->getBookId();

        return $id ? $this->entityManager->find(Book::class, $id) : null;
    }

    /** The invoice's VAT code in its book, when the book is liable and the code posts VAT; null otherwise. */
    public function taxOf(OwnershipInvoice $invoice): ?Tax
    {
        $book = $this->bookFor($invoice->getOwnership());
        if (null === $book || null === $invoice->getTaxCode() || !$book->getVatRegime()->isLiable()) {
            return null;
        }

        return $this->taxes?->findOneByCode($book, $invoice->getTaxCode());
    }

    /** What the co-owners bear: the amount, less the VAT the book deducts. */
    public function sharedAmount(OwnershipInvoice $invoice): int
    {
        $tax = $this->taxOf($invoice);
        if (null === $tax || TaxKind::EXEMPT === $tax->getKind() || TaxKind::COLLECTED === $tax->getKind() || null === $tax->getAccount()) {
            return $invoice->getAmount();
        }
        // A reverse charge's amount is its base already: the VAT due and deducted cancel out.
        if (TaxKind::REVERSE_CHARGE === $tax->getKind()) {
            return $invoice->getAmount();
        }

        return $tax->split($invoice->getAmount(), true)[0];
    }

    /**
     * The invoice (expense / supplier) and its calls (co-owner / 7551).
     *
     * @param list<OwnershipCall> $calls
     */
    public function invoicePosted(OwnershipInvoice $invoice, array $calls): void
    {
        $ownership = $invoice->getOwnership();
        if (!$book = $this->bookFor($ownership)) {
            return;
        }
        $dimensions = self::dimensions($ownership, $invoice->getUnit());
        $supplier = $invoice->getContractor()
            ? $this->parties->partyFor($book, PartyKind::SUPPLIER, 'contractor:'.$invoice->getContractor()->getId(), $invoice->getContractor()->getCompany(), $invoice->getContractor()->getIban())
            : $this->parties->partyFor($book, PartyKind::SUPPLIER, 'supplier:'.self::slug($invoice->getSupplier() ?: 'divers'), $invoice->getSupplier() ?: 'Fournisseur divers');

        $draft = new EntryDraft($book, 'AC', $invoice->getDate(), sprintf('%s — %s', $ownership->getName(), $invoice->getLabel()), 'estate:ownership:invoice:'.$invoice->getId(), $invoice->getNumber());
        $tax = $this->taxOf($invoice);
        if (null !== $tax && TaxKind::COLLECTED !== $tax->getKind()) {
            $draft->taxed($invoice->getExpenseAccount(), $invoice->getAmount(), 0, $tax, true, null, null, $dimensions);
        } else {
            $draft->line($invoice->getExpenseAccount(), $invoice->getAmount(), 0, dimensions: $dimensions);
        }
        $draft->line($supplier->getAccountNumber(), 0, $invoice->getAmount(), $supplier, dimensions: $dimensions);
        $this->posting->post($draft);

        foreach ($calls as $call) {
            $this->callPosted($call, $book, $dimensions, $invoice->getDate());
        }
    }

    /** A co-owner's part, due: their account debited, the expense transferred to them. */
    public function callPosted(OwnershipCall $call, ?Book $book = null, ?array $dimensions = null, ?\DateTimeImmutable $date = null): void
    {
        $book ??= $this->bookFor($call->getOwnership());
        if ($call->getShare()->isCompany() || null === $book) {
            return;
        }
        $this->ensureAccount($book, self::TRANSFERRED_LOSS, 'Quote-part de perte transférée (opérations en commun)', AccountType::INCOME);
        $party = $this->partyOf($book, $call->getShare());
        $dimensions ??= self::dimensions($call->getOwnership(), $call->getInvoice()?->getUnit());
        $draft = new EntryDraft($book, 'OD', $date ?? $call->getIssuedOn(), sprintf('Appel de fonds %s — %s (%s)', $call->getReference(), $call->getShare()->getName(), $call->getShare()->getFractionLabel()), 'estate:ownership:call:'.$call->getId(), $call->getReference());
        $draft->line($party->getAccountNumber(), $call->getAmount(), 0, $party, $call->getLabel(), $dimensions);
        $draft->line(self::TRANSFERRED_LOSS, 0, $call->getAmount(), null, $call->getLabel(), $dimensions);
        $this->posting->post($draft);
    }

    /**
     * A rent received for one of its homes: each co-owner but the managing
     * company credited with their part, by the shares that day.
     *
     * @param array<string, int> $parts holder => minor units (Ownership::allocate())
     */
    public function incomeReceived(Payment $payment, Ownership $ownership, array $parts): void
    {
        if (!$book = $this->bookFor($ownership)) {
            return;
        }
        $unit = $payment->getRentCall()->getLease()->getUnit();
        $dimensions = self::dimensions($ownership, $unit);
        // No piece: the rent notice's reference is the tenant's to quote, and must lead the reconciliation to the rent alone.
        $draft = new EntryDraft($book, 'OD', $payment->getPaidOn(), sprintf('%s — quotes-parts du loyer %s', $ownership->getName(), $payment->getRentCall()->getReference()), 'estate:ownership:income:'.$payment->getId());
        $total = 0;
        foreach ($parts as $holder => $part) {
            $share = $ownership->shareOf((string) $holder, $payment->getPaidOn());
            if (null === $share || $share->isCompany() || $part <= 0) {
                continue;
            }
            $party = $this->partyOf($book, $share);
            $draft->line($party->getAccountNumber(), 0, $part, $party, null, $dimensions);
            $total += $part;
        }
        if (0 === $total) {
            return;
        }
        $this->ensureAccount($book, self::TRANSFERRED_PROFIT, 'Quote-part de bénéfice transférée (opérations en commun)', AccountType::EXPENSE);
        $draft->line(self::TRANSFERRED_PROFIT, $total, 0, null, null, $dimensions);
        $this->posting->post($draft);
    }

    /** A rent payment taken back (a bank line unreconciled): its transfer to the co-owners reversed. */
    public function incomeCancelled(Payment $payment, Ownership $ownership): void
    {
        if (!$book = $this->bookFor($ownership)) {
            return;
        }
        $entry = $this->entityManager->getRepository(Entry::class)->findOneBy(['book' => $book, 'source' => 'estate:ownership:income:'.$payment->getId()]);
        if (null !== $entry) {
            $this->posting->reverse($entry, 'estate:ownership:income:'.$payment->getId().':cancelled');
        }
    }

    /** A payment recorded by hand: 512 / the co-owner, lettered with the call once it is paid. */
    public function paymentReceived(OwnershipPayment $payment): void
    {
        $call = $payment->getCall();
        if ($call->getShare()->isCompany() || !$book = $this->bookFor($call->getOwnership())) {
            return;
        }
        $party = $this->partyOf($book, $call->getShare());
        $dimensions = self::dimensions($call->getOwnership(), $call->getInvoice()?->getUnit());
        $draft = new EntryDraft($book, 'BQ', $payment->getPaidOn(), sprintf('Versement %s — %s', $call->getReference(), $call->getShare()->getName()), 'estate:ownership:payment:'.$payment->getId(), $call->getReference());
        $draft->line('512', $payment->getAmount(), 0, null, null, $dimensions);
        $draft->line($party->getAccountNumber(), 0, $payment->getAmount(), $party, null, $dimensions);
        $this->posting->post($draft);

        if (!$call->isPaid()) {
            return;
        }
        $lines = [];
        $sources = ['estate:ownership:call:'.$call->getId(), ...array_map(fn (OwnershipPayment $p) => 'estate:ownership:payment:'.$p->getId(), $call->getPayments()->toArray())];
        foreach ($sources as $source) {
            $entry = $this->entityManager->getRepository(Entry::class)->findOneBy(['book' => $book, 'source' => $source]);
            foreach ($entry?->getLines() ?? [] as $line) {
                if ($line->getParty() === $party && !$line->isLettered()) {
                    $lines[] = $line;
                }
            }
        }
        if (\count($lines) >= 2 && 0 === array_sum(array_map(fn (EntryLine $l) => $l->getNet(), $lines))) {
            $this->lettering->letter($lines);
            $this->entityManager->flush();
        }
    }

    /** The co-owner's current account: 467 + a reference that stays theirs across their shares. */
    public function partyOf(Book $book, OwnershipShare $share): Party
    {
        $ownership = $share->getOwnership();
        $first = $share;
        foreach ($ownership->getShares() as $other) {
            if ($other->holder() === $share->holder() && null !== $other->getId() && (null === $first->getId() || $other->getId() < $first->getId())) {
                $first = $other;
            }
        }

        return $this->parties->partyFor($book, PartyKind::OTHER, sprintf('ind%d-%d', $ownership->getId(), $first->getId()), sprintf('%s — %s', $share->getName(), $ownership->getName()), $share->getIban());
    }

    /** @return array<string, string> ownership=<id>, and unit=<id> for one of its homes */
    public static function dimensions(Ownership $ownership, ?\Base\Estate\Entity\Unit $unit = null): array
    {
        return (null === $ownership->getId() ? [] : [OwnershipDimensionLabeler::AXIS => (string) $ownership->getId()]) + Books::unit($unit);
    }

    private function ensureAccount(Book $book, string $number, string $label, AccountType $type): void
    {
        if (null === $this->accounts || null !== $this->accounts->findOneByNumber($book, $number)) {
            return;
        }
        $this->entityManager->persist(new Account($book, $number, $label, $type));
        $this->entityManager->flush();
    }

    private static function slug(string $name): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;

        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-') ?: 'divers';
    }
}
