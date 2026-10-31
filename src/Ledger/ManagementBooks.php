<?php

namespace Base\Estate\Ledger;

use Base\Estate\Entity\Mandate;
use Base\Estate\Entity\MandateExpense;
use Base\Estate\Entity\MandateFee;
use Base\Estate\Entity\MandatePayout;
use Base\Estate\Entity\Mandator;
use Base\Estate\Entity\Payment;
use Base\Estate\Entity\RentCall;
use Base\Estate\Entity\Unit;
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
use Base\Ledger\Repository\BankAccountRepository;
use Base\Ledger\Repository\TaxRepository;
use Base\Ledger\Service\Lettering;
use Base\Ledger\Service\Parties;
use Base\Ledger\Service\Posting;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Property management for third parties in the agency's books - the
 * owners' money is not the agency's (loi Hoguet): it sits on the escrow
 * bank account (BankAccount::isEscrow(), 512 otherwise) and on each
 * owner's account, 4671 + their reference (PartyKind::MANDATOR). The
 * agency's income is its fees. Every line carries the dimensions
 * owner=<mandator id> and unit=<id> when it is about one home.
 *
 * - A rent called for a managed home: 411 the tenant / 4672 « loyers
 *   appelés pour les mandants » - the tenant's debt is collected for the
 *   owner, nothing is the agency's (no 706); its reference as the piece,
 *   so the bank reconciliation matches the tenant's transfer as for any rent.
 * - The rent collected (whoever posted the money: by hand, online, a
 *   transfer reconciled): 4672 / 4671 the owner; 4672 lettered once the
 *   call is paid.
 * - The agency's fee on it: 4671 the owner / 7061 « honoraires de gestion »
 *   and 44571 its VAT (EntryDraft::taxed(), when the book is liable).
 * - An expense paid for the owner: 4671 the owner / 401 the supplier.
 * - A payout issued: 4671 the owner / 4678 « reversements aux mandants »,
 *   its reference (RV-2026-0001, the SEPA end-to-end id) as the piece; paid
 *   (the bank line reconciled against it, or by hand): 4678 / the escrow
 *   account, lettered.
 *
 * Each posting carries its source: nothing twice. Only loaded when
 * omnibase/ledger is installed (config/services.php).
 */
class ManagementBooks
{
    public const CALLED = '4672';
    public const PAYOUTS = '4678';
    public const FEES = '7061';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Posting $posting,
        private readonly Parties $parties,
        private readonly Lettering $lettering,
        private readonly ?TaxRepository $taxes = null,
        private readonly ?AccountRepository $accounts = null,
        private readonly ?BankAccountRepository $bankAccounts = null,
    ) {
    }

    public function bookFor(Mandate $mandate): ?Book
    {
        $id = $mandate->getOrganisation()->getBookId();

        return $id ? $this->entityManager->find(Book::class, $id) : null;
    }

    /** Where the owners' money is: the escrow account's 512x, 512 when the book has none. */
    public function cashAccount(Book $book): string
    {
        return $this->bankAccounts?->escrowFor($book)?->getAccount()->getNumber() ?? '512';
    }

    /** The escrow account's IBAN: the payouts' debtor account. */
    public function escrowIban(Book $book): ?string
    {
        return $this->bankAccounts?->escrowFor($book)?->getIban();
    }

    /** The fee's VAT code in the agency's book, when the book is liable and the code collects VAT; null otherwise. */
    public function feeTax(Mandate $mandate): ?Tax
    {
        $book = $this->bookFor($mandate);
        if (null === $book || null === $mandate->getTaxCode() || !$book->getVatRegime()->isLiable()) {
            return null;
        }
        $tax = $this->taxes?->findOneByCode($book, $mandate->getTaxCode());

        return null !== $tax && TaxKind::COLLECTED === $tax->getKind() ? $tax : null;
    }

    /** A rent called for a managed home: the tenant's debt, collected for the owner (411 / 4672). */
    public function rentCalled(RentCall $call, Mandate $mandate): void
    {
        $lease = $call->getLease();
        if (!$book = $this->bookFor($mandate)) {
            return;
        }
        $this->ensureAccount($book, self::CALLED, 'Mandants — loyers appelés à encaisser', AccountType::LIABILITY);
        $tenant = $lease->getTenants()[0] ?? null;
        $party = $this->parties->partyFor($book, PartyKind::TENANT, 'lease:'.$lease->getReference(), $lease->getTenantNames() ?: $lease->getReference(), $tenant?->getIban());
        $dimensions = self::dimensions($mandate, $lease->getUnit());
        // The same source as an own home's rent: the bank settlement and the reminders find it alike.
        $draft = new EntryDraft($book, 'VT', $call->getPeriod(), sprintf('Loyer %s %s (pour %s)', $call->getPeriod()->format('m/Y'), $lease->getReference(), $mandate->getMandator()->getName()), 'estate:rent:'.$call->getReference(), $call->getReference());
        $draft->line($party->getAccountNumber(), $call->getTotal(), 0, $party, dimensions: $dimensions);
        $draft->line(self::CALLED, 0, $call->getTotal(), dimensions: $dimensions);
        $this->posting->post($draft);
    }

    /** The rent collected: the owner's (4672 / 4671), the call's 4672 lettered once paid. */
    public function rentCollected(Payment $payment, Mandate $mandate): void
    {
        if (!$book = $this->bookFor($mandate)) {
            return;
        }
        $call = $payment->getRentCall();
        $this->ensureAccount($book, self::CALLED, 'Mandants — loyers appelés à encaisser', AccountType::LIABILITY);
        $party = $this->partyOf($book, $mandate->getMandator());
        $dimensions = self::dimensions($mandate, $call->getLease()->getUnit());
        // No piece: the rent's reference leads the reconciliation to the tenant's notice alone.
        $draft = new EntryDraft($book, 'OD', $payment->getPaidOn(), sprintf('Loyer encaissé %s — pour %s', $call->getReference(), $mandate->getMandator()->getName()), 'estate:management:rent:'.$payment->getId());
        $draft->line(self::CALLED, $payment->getAmount(), 0, null, null, $dimensions);
        $draft->line($party->getAccountNumber(), 0, $payment->getAmount(), $party, null, $dimensions);
        $this->posting->post($draft);

        if (!$call->isPaid()) {
            return;
        }
        $sources = ['estate:rent:'.$call->getReference(), ...array_map(fn (Payment $p) => 'estate:management:rent:'.$p->getId(), $call->getPayments()->toArray())];
        $this->letter($book, $sources, fn (EntryLine $line) => self::CALLED === $line->getAccount()->getNumber());
    }

    /** A rent payment taken back (a bank line unreconciled): its credit to the owner reversed. */
    public function rentCancelled(Payment $payment, Mandate $mandate): void
    {
        $this->reverse($mandate, 'estate:management:rent:'.$payment->getId());
    }

    /** The agency's fee, taken from the owner's account: 4671 / 7061 + 44571. */
    public function feeCharged(MandateFee $fee): void
    {
        $mandate = $fee->getMandate();
        if ($fee->getTotal() <= 0 || !$book = $this->bookFor($mandate)) {
            return;
        }
        $this->ensureAccount($book, self::FEES, 'Honoraires de gestion locative', AccountType::INCOME);
        $party = $this->partyOf($book, $mandate->getMandator());
        $dimensions = self::dimensions($mandate, $fee->getUnit());
        $draft = new EntryDraft($book, 'VT', $fee->getDate(), sprintf('Honoraires de gestion — %s', $fee->getLabel()), 'estate:management:fee:'.$fee->getId());
        $draft->line($party->getAccountNumber(), $fee->getTotal(), 0, $party, null, $dimensions);
        $tax = $fee->getVat() > 0 ? $this->feeTax($mandate) : null;
        if (null !== $tax) {
            $draft->taxed(self::FEES, 0, $fee->getAmount(), $tax, false, null, null, $dimensions);
        } else {
            $draft->line(self::FEES, 0, $fee->getTotal(), null, null, $dimensions);
        }
        $this->posting->post($draft);
    }

    public function feeCancelled(MandateFee $fee): void
    {
        $this->reverse($fee->getMandate(), 'estate:management:fee:'.$fee->getId());
    }

    /** An expense paid for the owner: 4671 the owner / 401 the supplier. */
    public function expenseCharged(MandateExpense $expense): void
    {
        $mandate = $expense->getMandate();
        if ($expense->getAmount() <= 0 || !$book = $this->bookFor($mandate)) {
            return;
        }
        $party = $this->partyOf($book, $mandate->getMandator());
        $supplier = $expense->getContractor()
            ? $this->parties->partyFor($book, PartyKind::SUPPLIER, 'contractor:'.$expense->getContractor()->getId(), $expense->getContractor()->getCompany(), $expense->getContractor()->getIban())
            : $this->parties->partyFor($book, PartyKind::SUPPLIER, 'supplier:'.self::slug($expense->getSupplierName() ?: 'divers'), $expense->getSupplierName() ?: 'Fournisseur divers');
        $dimensions = self::dimensions($mandate, $expense->getUnit());
        $draft = new EntryDraft($book, 'AC', $expense->getDate(), sprintf('%s — pour %s', $expense->getLabel(), $mandate->getMandator()->getName()), 'estate:management:expense:'.$expense->getId(), $expense->getNumber());
        $draft->line($party->getAccountNumber(), $expense->getAmount(), 0, $party, null, $dimensions);
        $draft->line($supplier->getAccountNumber(), 0, $expense->getAmount(), $supplier, null, $dimensions);
        $this->posting->post($draft);
    }

    /** A payout issued: the owner's account settled, the transfer to make (4671 / 4678, its reference as the piece). */
    public function payoutIssued(MandatePayout $payout): void
    {
        $mandate = $payout->getMandate();
        if (!$book = $this->bookFor($mandate)) {
            return;
        }
        $this->ensureAccount($book, self::PAYOUTS, 'Mandants — reversements à payer', AccountType::LIABILITY);
        $party = $this->partyOf($book, $mandate->getMandator());
        $dimensions = self::dimensions($mandate);
        $draft = new EntryDraft($book, 'OD', $payout->getIssuedOn(), sprintf('Reversement %s — %s', $payout->getReference(), $mandate->getMandator()->getName()), 'estate:management:payout:'.$payout->getId(), $payout->getReference());
        $draft->line($party->getAccountNumber(), $payout->getAmount(), 0, $party, null, $dimensions);
        $draft->line(self::PAYOUTS, 0, $payout->getAmount(), null, null, $dimensions);
        $this->posting->post($draft);
    }

    /** A payout paid by hand: 4678 / the escrow account, lettered (a bank line reconciled posts and letters it itself). */
    public function payoutPaid(MandatePayout $payout): void
    {
        $mandate = $payout->getMandate();
        if (!$book = $this->bookFor($mandate)) {
            return;
        }
        $dimensions = self::dimensions($mandate);
        $draft = new EntryDraft($book, 'BQ', $payout->getPaidOn() ?? new \DateTimeImmutable('today'), sprintf('Virement %s — %s', $payout->getReference(), $mandate->getMandator()->getName()), 'estate:management:payout:'.$payout->getId().':paid', $payout->getReference());
        $draft->line(self::PAYOUTS, $payout->getAmount(), 0, null, null, $dimensions);
        $draft->line($this->cashAccount($book), 0, $payout->getAmount(), null, null, $dimensions);
        $this->posting->post($draft);
        $this->letter($book, ['estate:management:payout:'.$payout->getId(), 'estate:management:payout:'.$payout->getId().':paid'], fn (EntryLine $line) => self::PAYOUTS === $line->getAccount()->getNumber());
    }

    /** A payout taken back before it was paid: its entry reversed. */
    public function payoutCancelled(MandatePayout $payout): void
    {
        $this->reverse($payout->getMandate(), 'estate:management:payout:'.$payout->getId());
    }

    /**
     * The owner's account: 4671 + their reference. No IBAN on it: their
     * payouts leave by their reference, and a transfer to them must not be
     * matched against their rents by the IBAN.
     */
    public function partyOf(Book $book, Mandator $mandator): Party
    {
        return $this->parties->partyFor($book, PartyKind::MANDATOR, 'M'.$mandator->getId(), sprintf('%s (mandant)', $mandator->getName()));
    }

    /** @return array<string, string> owner=<mandator id>, and unit=<id> for one of the homes */
    public static function dimensions(Mandate $mandate, ?Unit $unit = null): array
    {
        $id = $mandate->getMandator()->getId();

        return (null === $id ? [] : [OwnerDimensionLabeler::AXIS => (string) $id]) + Books::unit($unit);
    }

    private function reverse(Mandate $mandate, string $source): void
    {
        if (!$book = $this->bookFor($mandate)) {
            return;
        }
        $entry = $this->entityManager->getRepository(Entry::class)->findOneBy(['book' => $book, 'source' => $source]);
        if (null !== $entry) {
            $this->posting->reverse($entry, $source.':cancelled');
        }
    }

    /**
     * The lines of these entries an account holds, lettered when they cancel out.
     *
     * @param list<string>              $sources
     * @param callable(EntryLine): bool $keep
     */
    private function letter(Book $book, array $sources, callable $keep): void
    {
        $lines = [];
        foreach ($sources as $source) {
            $entry = $this->entityManager->getRepository(Entry::class)->findOneBy(['book' => $book, 'source' => $source]);
            foreach ($entry?->getLines() ?? [] as $line) {
                if ($keep($line) && !$line->isLettered()) {
                    $lines[] = $line;
                }
            }
        }
        if (\count($lines) >= 2 && 0 === array_sum(array_map(fn (EntryLine $l) => $l->getNet(), $lines))) {
            $this->lettering->letter($lines);
            $this->entityManager->flush();
        }
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
