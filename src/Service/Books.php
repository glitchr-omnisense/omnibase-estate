<?php

namespace Base\Estate\Service;

use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\Payment;
use Base\Estate\Entity\RentCall;
use Base\Estate\Entity\WorkOrder;
use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\Entry;
use Base\Ledger\Entity\EntryLine;
use Base\Ledger\Model\EntryDraft;
use Base\Ledger\Service\Lettering;
use Base\Ledger\Service\Parties;
use Base\Ledger\Service\Posting;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The organisation's books (omnibase/ledger), when it keeps them here:
 * a rent call is a receivable (411 / 706 rent, 708 charges), a payment
 * settles it (512 for a transfer, 5112 for a card or SEPA payment until the
 * provider's payout), a work invoice is a supplier debt (615 / 401). Each
 * posting carries its source, so nothing is posted twice.
 * Only loaded when omnibase/ledger is installed (config/services.php).
 */
class Books
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Posting $posting,
        private readonly Parties $parties,
        private readonly Lettering $lettering,
    ) {
    }

    public function bookFor(Organisation $organisation): ?Book
    {
        return $organisation->getBookId() ? $this->entityManager->find(Book::class, $organisation->getBookId()) : null;
    }

    public function rentCalled(RentCall $call): void
    {
        $lease = $call->getLease();
        if (!$book = $this->bookFor($lease->getOrganisation())) {
            return;
        }
        $tenant = $lease->getTenants()[0] ?? null;
        $party = $this->parties->partyFor($book, 'tenant', 'lease:'.$lease->getReference(), $lease->getTenantNames() ?: $lease->getReference(), $tenant?->getIban());

        $draft = new EntryDraft($book, 'VT', $call->getPeriod(), sprintf('Loyer %s %s', $call->getPeriod()->format('m/Y'), $lease->getReference()), 'estate:rent:'.$call->getReference(), $call->getReference());
        $draft->line($party->getAccountNumber(), $call->getTotal(), 0, $party);
        $draft->line('706', 0, $call->getRent());
        if ($call->getCharges() > 0) {
            $draft->line('708', 0, $call->getCharges());
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

        $draft = new EntryDraft($book, 'BQ', $payment->getPaidOn(), sprintf('Règlement %s', $call->getReference()), 'estate:payment:'.$payment->getId(), $call->getReference());
        $draft->line($payment->getMethod()->isOnline() ? '5112' : '512', $payment->getAmount(), 0);
        $draft->line($party->getAccountNumber(), 0, $payment->getAmount(), $party);
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
        $contractor = $order->getContractor();
        $party = $this->parties->partyFor($book, 'supplier', 'contractor:'.$contractor->getId(), $contractor->getCompany(), $contractor->getIban());

        $draft = new EntryDraft($book, 'AC', $order->getClosedAt() ?? new \DateTimeImmutable('today'), sprintf('Travaux %s — %s', $order->getUnit()->getName(), $order->getTitle()), 'estate:work:'.$order->getId(), $order->getInvoiceNumber());
        $draft->line('615', $order->getInvoiceAmount(), 0);
        $draft->line($party->getAccountNumber(), 0, $order->getInvoiceAmount(), $party);
        $this->posting->post($draft);
    }
}
