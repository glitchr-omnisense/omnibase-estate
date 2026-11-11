<?php

namespace Base\Estate\Service;

use Base\Estate\Entity\Lease;
use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\Payment;
use Base\Estate\Entity\RentCall;
use Base\Estate\Entity\Unit;
use Base\Estate\Entity\WorkOrder;
use Base\Estate\Ledger\UnitDimensionLabeler;
use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\Entry;
use Base\Ledger\Entity\EntryLine;
use Base\Ledger\Entity\Tax;
use Base\Ledger\Enum\TaxKind;
use Base\Ledger\Model\EntryDraft;
use Base\Ledger\Repository\TaxRepository;
use Base\Ledger\Service\Lettering;
use Base\Ledger\Service\Parties;
use Base\Ledger\Service\Posting;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The organisation's books (omnibase/ledger), when it keeps them here:
 * a rent call is a receivable (411 / 706 rent, 708 charges), a payment
 * settles it (512 for a transfer, 5112 for a card or SEPA payment until the
 * provider's payout), a work invoice is a supplier debt (615 / 401). Each
 * posting carries its source, so nothing is posted twice, and its unit as
 * an analytic dimension on every line (unit=<id>, named by
 * Ledger\UnitDimensionLabeler): the books give each home's result.
 * A home managed for its owner (the `management` activity) is posted the
 * owner's way instead: its rent called for them, its money on the escrow
 * account, its work invoices charged to them (Service\Management,
 * Ledger\ManagementBooks).
 * A lease whose landlord opted for VAT (Lease::getTaxCode()) has its rent -
 * and its charges, if it says so - called with that code's VAT (vatOf(), fixed
 * on the call) and posted 706 / 708 before tax, the VAT on the code's account
 * (44571), its base on the line (EntryDraft::taxed()), which ledger's VAT
 * return reads. Only when the book is liable and the code collects VAT; a
 * managed home's rent is its owner's and is never called with VAT here.
 * Only loaded when omnibase/ledger is installed (config/services.php).
 */
class Books
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Posting $posting,
        private readonly Parties $parties,
        private readonly Lettering $lettering,
        private readonly ?Management $management = null,
        private readonly ?TaxRepository $taxes = null,
    ) {
    }

    /**
     * The VAT a call of $lease carries on $rent and $charges (cents, before tax), and its code: null
     * when the lease names no code, or the book is not liable, does not know it or it collects none.
     *
     * @return array{vat: int, code: string}|null
     */
    public function vatOf(Lease $lease, int $rent, int $charges, ?\DateTimeImmutable $on = null): ?array
    {
        $tax = $this->tax($lease, $on);
        if (null === $tax) {
            return null;
        }
        $vat = $tax->split($rent, false)[1] + ($lease->isTaxCharges() ? $tax->split($charges, false)[1] : 0);

        return $vat > 0 ? ['vat' => $vat, 'code' => $tax->getCode()] : null;
    }

    /** The lease's VAT code in its book, when the rent can be called with it. */
    public function tax(Lease $lease, ?\DateTimeImmutable $on = null): ?Tax
    {
        $book = $this->bookFor($lease->getOrganisation());
        if (null === $lease->getTaxCode() || null === $book || null === $this->taxes || !$book->getVatRegime()->isLiable()
            || $this->management?->isManaged($lease->getUnit(), $on ?? new \DateTimeImmutable('today'))) {
            return null;
        }
        $tax = $this->taxes->findOneByCode($book, $lease->getTaxCode());

        return null !== $tax && TaxKind::COLLECTED === $tax->getKind() ? $tax : null;
    }

    public function bookFor(Organisation $organisation): ?Book
    {
        return $organisation->getBookId() ? $this->entityManager->find(Book::class, $organisation->getBookId()) : null;
    }

    public function rentCalled(RentCall $call): void
    {
        $lease = $call->getLease();
        // A home managed for its owner: the rent is theirs, not the company's income.
        if ($this->management?->rentCalled($call)) {
            return;
        }
        if (!$book = $this->bookFor($lease->getOrganisation())) {
            return;
        }
        $tenant = $lease->getTenants()[0] ?? null;
        $party = $this->parties->partyFor($book, 'tenant', 'lease:'.$lease->getReference(), $lease->getTenantNames() ?: $lease->getReference(), $tenant?->getIban());

        $unit = self::unit($lease->getUnit());
        $draft = new EntryDraft($book, 'VT', $call->getPeriod(), sprintf('Loyer %s %s', $call->getPeriod()->format('m/Y'), $lease->getReference()), 'estate:rent:'.$call->getReference(), $call->getReference());
        $draft->line($party->getAccountNumber(), $call->getTotal(), 0, $party, dimensions: $unit);
        // Called with VAT: the rent (and the charges, if the lease says so) before tax, the VAT on the code's account.
        $tax = $call->getVat() > 0 && null !== $call->getTaxCode() ? $this->taxes?->findOneByCode($book, $call->getTaxCode()) : null;
        if (null !== $tax) {
            $draft->taxed('706', 0, $call->getRent(), $tax, false, dimensions: $unit);
        } else {
            $draft->line('706', 0, $call->getRent(), dimensions: $unit);
        }
        if ($call->getCharges() > 0) {
            null !== $tax && $lease->isTaxCharges()
                ? $draft->taxed('708', 0, $call->getCharges(), $tax, false, dimensions: $unit)
                : $draft->line('708', 0, $call->getCharges(), dimensions: $unit);
        }
        $this->posting->post($draft);
    }

    public function paymentReceived(Payment $payment): void
    {
        $call = $payment->getRentCall();
        $lease = $call->getLease();
        if (!$book = $this->bookFor($lease->getOrganisation())) {
            return;
        }
        $party = $this->parties->partyFor($book, 'tenant', 'lease:'.$lease->getReference(), $lease->getTenantNames() ?: $lease->getReference());

        $unit = self::unit($lease->getUnit());
        $draft = new EntryDraft($book, 'BQ', $payment->getPaidOn(), sprintf('Règlement %s', $call->getReference()), 'estate:payment:'.$payment->getId(), $call->getReference());
        // A managed home's money lands on the escrow account (the owners' money, loi Hoguet).
        $cash = $payment->getMethod()->isOnline() ? '5112' : ($this->management?->cashAccount($lease->getUnit(), $payment->getPaidOn()) ?? '512');
        $draft->line($cash, $payment->getAmount(), 0, dimensions: $unit);
        $draft->line($party->getAccountNumber(), 0, $payment->getAmount(), $party, dimensions: $unit);
        $this->posting->post($draft);

        // Paid in full: the call and its payments lettered on the tenant's account, nothing left open there.
        if ($call->isPaid()) {
            $lines = [];
            foreach (['estate:rent:'.$call->getReference(), ...array_map(fn ($p) => 'estate:payment:'.$p->getId(), $call->getPayments()->toArray())] as $source) {
                $entry = $this->entityManager->getRepository(Entry::class)->findOneBy(['book' => $book, 'source' => $source]);
                foreach ($entry?->getLines() ?? [] as $line) {
                    if ($line->getParty() === $party && !$line->isLettered()) {
                        $lines[] = $line;
                    }
                }
            }
            $net = array_sum(array_map(fn (EntryLine $l) => $l->getNet(), $lines));
            if (\count($lines) >= 2 && 0 === $net) {
                $this->lettering->letter($lines);
                $this->entityManager->flush();
            }
        }
    }

    public function workInvoiced(WorkOrder $order): void
    {
        if (!$order->getInvoiceAmount() || !$order->getContractor() || !$book = $this->bookFor($order->getOrganisation())) {
            return;
        }
        // A managed home's work is its owner's: Management::workInvoiced() charges it to them.
        if ($this->management?->isManaged($order->getUnit(), $order->getClosedAt() ?? new \DateTimeImmutable('today'))) {
            return;
        }
        $contractor = $order->getContractor();
        $party = $this->parties->partyFor($book, 'supplier', 'contractor:'.$contractor->getId(), $contractor->getCompany(), $contractor->getIban());

        $draft = new EntryDraft($book, 'AC', $order->getClosedAt() ?? new \DateTimeImmutable('today'), sprintf('Travaux %s — %s', $order->getUnit()->getName(), $order->getTitle()), 'estate:work:'.$order->getId(), $order->getInvoiceNumber());
        $unit = self::unit($order->getUnit());
        $draft->line('615', $order->getInvoiceAmount(), 0, dimensions: $unit);
        $draft->line($party->getAccountNumber(), 0, $order->getInvoiceAmount(), $party, dimensions: $unit);
        $this->posting->post($draft);
    }

    /**
     * Gives the unit's dimension to the lines of what estate posted before
     * it carried one (rent calls, payments, work invoices): the axis is
     * added where a line has not got it, the amounts untouched. Returns how
     * many lines got it.
     */
    public function tagUnits(): int
    {
        $tagged = 0;
        $tag = function (?Book $book, string $source, ?Unit $unit) use (&$tagged): void {
            $entry = null === $book ? null : $this->entityManager->getRepository(Entry::class)->findOneBy(['book' => $book, 'source' => $source]);
            foreach (self::unit($unit) as $axis => $value) {
                foreach ($entry?->getLines() ?? [] as $line) {
                    $tagged += $line->addDimension($axis, $value) ? 1 : 0;
                }
            }
        };
        foreach ($this->entityManager->getRepository(RentCall::class)->findAll() as $call) {
            $lease = $call->getLease();
            $book = $this->bookFor($lease->getOrganisation());
            $tag($book, 'estate:rent:'.$call->getReference(), $lease->getUnit());
            foreach ($call->getPayments() as $payment) {
                $tag($book, 'estate:payment:'.$payment->getId(), $lease->getUnit());
            }
        }
        foreach ($this->entityManager->getRepository(WorkOrder::class)->findAll() as $order) {
            $tag($this->bookFor($order->getOrganisation()), 'estate:work:'.$order->getId(), $order->getUnit());
        }
        $this->entityManager->flush();

        return $tagged;
    }

    /** @return array<string, string> the unit's analytic dimension, none for a unit not saved yet */
    public static function unit(?Unit $unit): array
    {
        return null === $unit?->getId() ? [] : [UnitDimensionLabeler::AXIS => (string) $unit->getId()];
    }
}
