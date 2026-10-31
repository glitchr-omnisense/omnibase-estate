<?php

namespace Base\Estate\Service;

use Base\Entity\User;
use Base\Estate\Drive\OwnershipFolders;
use Base\Estate\Entity\Ownership;
use Base\Estate\Entity\OwnershipCall;
use Base\Estate\Entity\OwnershipInvoice;
use Base\Estate\Entity\OwnershipPayment;
use Base\Estate\Entity\OwnershipShare;
use Base\Estate\Entity\Payment;
use Base\Estate\Enum\Activity;
use Base\Estate\Enum\ActivityKind;
use Base\Estate\Enum\SplitMode;
use Base\Estate\Ledger\OwnershipBooks;
use Base\Estate\Model\OwnershipStatement;
use Base\Estate\Model\Shares;
use Base\Estate\Repository\OwnershipCallRepository;
use Base\Estate\Repository\OwnershipRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Twig\Environment;

/**
 * Shared ownership (the `ownership` activity): a shared invoice divided
 * among the co-owners and posted, their calls for funds numbered, sent and
 * paid, the rents of its homes shared out, each co-owner's statement, the
 * ownership's cloud folder. The books are omnibase/ledger's
 * (Ledger\OwnershipBooks), the folder omnibase/drive's
 * (Drive\OwnershipFolders): both optional.
 *
 * Nothing here reads the ownership tables while the site does not run the
 * activity: rentReceived(), sharesOf() answer nothing then.
 */
class Ownerships
{
    /** @var array<string, int> the last call number given per prefix in this request, ahead of the flush */
    private array $numbers = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Activities $activities,
        private readonly OwnershipRepository $ownerships,
        private readonly OwnershipCallRepository $calls,
        private readonly EstateMailer $mailer,
        private readonly Receipts $receipts,
        private readonly Environment $twig,
        private readonly ActivityLog $log,
        private readonly ?OwnershipBooks $books = null,
        private readonly ?OwnershipFolders $folders = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function isActive(): bool
    {
        return $this->activities->has(Activity::OWNERSHIP);
    }

    public function hasBooks(): bool
    {
        return null !== $this->books;
    }

    public function hasCloud(): bool
    {
        return null !== $this->folders;
    }

    /** What the co-owners bear of an invoice: its amount, less the VAT the books get back. */
    public function sharedAmount(OwnershipInvoice $invoice): int
    {
        return $this->books?->sharedAmount($invoice) ?? $invoice->getAmount();
    }

    /**
     * Each co-owner's part of an invoice: by the shares in force on its
     * date, or as set by hand - amounts adding up to the shared cost, or
     * percentages (basis points) adding up to 100 %. A preview: nothing is
     * saved.
     *
     * @param array<string, int>|null $split holder => minor units or basis points; the invoice's own when null
     *
     * @return array{shared: int, parts: array<string, array{share: ?OwnershipShare, fraction: string, amount: int}>, errors: list<string>}
     */
    public function split(OwnershipInvoice $invoice, ?SplitMode $mode = null, ?array $split = null): array
    {
        $mode ??= $invoice->getSplitMode();
        $split ??= $invoice->getSplit();
        $ownership = $invoice->getOwnership();
        $date = $invoice->getDate();
        $shared = $this->sharedAmount($invoice);
        $fractions = $ownership->fractionsOn($date);
        $errors = [];
        $amounts = [];

        if ([] === $fractions) {
            $errors[] = sprintf('Aucune quote-part en vigueur au %s.', $date->format('d/m/Y'));
        }
        switch ($mode) {
            case SplitMode::SHARES:
                if (!Shares::isWhole($fractions)) {
                    [$n, $d] = Shares::sum($fractions);
                    $errors[] = sprintf('Les quotes-parts en vigueur au %s font %s, pas 1.', $date->format('d/m/Y'), Shares::format($n, $d));
                } else {
                    $amounts = Shares::allocate($shared, $fractions);
                }
                break;
            case SplitMode::AMOUNTS:
                foreach (array_keys($fractions + $split) as $holder) {
                    $amounts[$holder] = max(0, (int) ($split[$holder] ?? 0));
                }
                if (array_sum($amounts) !== $shared) {
                    $errors[] = sprintf('Les montants font %s, la part à répartir %s.', self::euros(array_sum($amounts)), self::euros($shared));
                }
                break;
            case SplitMode::PERCENTAGES:
                $points = [];
                foreach (array_keys($fractions + $split) as $holder) {
                    $points[$holder] = max(0, (int) ($split[$holder] ?? 0));
                }
                if (10000 !== array_sum($points)) {
                    $errors[] = sprintf('Les pourcentages font %s %%, pas 100 %%.', number_format(array_sum($points) / 100, 2, ',', ' '));
                } else {
                    $amounts = Shares::allocate($shared, array_map(fn (int $p) => [$p, 10000], $points));
                }
                break;
        }

        $parts = [];
        foreach ($amounts + array_fill_keys(array_keys($fractions), 0) as $holder => $amount) {
            $share = $ownership->shareOf((string) $holder, $date);
            if (null === $share) {
                $errors[] = sprintf('« %s » n\'est pas indivisaire au %s.', $holder, $date->format('d/m/Y'));
                continue;
            }
            $parts[(string) $holder] = [
                'share' => $share,
                'fraction' => isset($fractions[$holder]) ? Shares::format(...$fractions[$holder]) : '0',
                'amount' => $amount,
            ];
        }

        return ['shared' => $shared, 'parts' => $parts, 'errors' => array_values(array_unique($errors))];
    }

    /**
     * Posts a shared invoice: each co-owner's part (but the managing
     * company's) called for funds, numbered; the invoice and the calls in
     * the books; the file into the ownership's cloud folder; the calls
     * e-mailed when $send. Once only: posted again, it gives its calls back.
     *
     * @return list<OwnershipCall>
     *
     * @throws \InvalidArgumentException the split does not hold (split()'s errors)
     */
    public function post(OwnershipInvoice $invoice, ?\DateTimeImmutable $dueOn = null, bool $send = true): array
    {
        if ($invoice->isPosted()) {
            return $invoice->getCalls()->toArray();
        }
        if ($invoice->getAmount() <= 0) {
            throw new \InvalidArgumentException('Une facture partagée a un montant.');
        }
        $split = $this->split($invoice);
        if ([] !== $split['errors']) {
            throw new \InvalidArgumentException(implode(' ', $split['errors']));
        }

        $today = new \DateTimeImmutable('today');
        $dueOn ??= max($today, $invoice->getDate())->modify('+30 days');
        $calls = [];
        $parts = [];
        foreach ($split['parts'] as $holder => $part) {
            $parts[$holder] = $part['amount'];
            if ($part['share']->isCompany() || $part['amount'] <= 0) {
                continue;
            }
            $calls[] = $this->call($part['share'], $invoice->getLabel(), $part['amount'], $dueOn, $invoice, $today);
        }
        $invoice->markPosted($parts, $split['shared']);
        $this->entityManager->flush();

        $this->books?->invoicePosted($invoice, $calls);
        try {
            $this->folders?->file($invoice);
        } catch (\Throwable $e) {
            // The cloud never stops a posting: the file stays on the invoice.
            $this->logger?->warning('The invoice {id} did not reach the ownership\'s folder: {message}', ['id' => $invoice->getId(), 'message' => $e->getMessage()]);
        }
        $ownership = $invoice->getOwnership();
        $this->log->log($ownership->getOrganisation(), ActivityKind::OTHER, sprintf('%s : facture « %s » répartie, %d appel(s) de fonds', $ownership->getName(), $invoice->getLabel(), \count($calls)), $invoice->getUnit(), -$invoice->getAmount());
        $this->entityManager->flush();

        if ($send) {
            foreach ($calls as $call) {
                $this->send($call);
            }
        }

        return $calls;
    }

    /**
     * A call for funds to a co-owner, numbered and saved. A provision called
     * by hand (no invoice) is a request: the books get what is paid on it.
     */
    public function call(OwnershipShare $share, string $label, int $amount, ?\DateTimeImmutable $dueOn = null, ?OwnershipInvoice $invoice = null, ?\DateTimeImmutable $issuedOn = null): OwnershipCall
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Un appel de fonds est d\'un montant positif.');
        }
        $call = (new OwnershipCall($share, $label, $amount, $dueOn, $issuedOn))->setInvoice($invoice);
        $this->number($call);
        $this->entityManager->persist($call);
        $this->entityManager->flush();

        return $call;
    }

    /** "AF-2026-0007": the year it is issued, in the order calls are made. */
    public function number(OwnershipCall $call): string
    {
        if (null !== $call->getReference()) {
            return $call->getReference();
        }
        $prefix = 'AF-'.$call->getIssuedOn()->format('Y').'-';
        if (!isset($this->numbers[$prefix])) {
            $last = $this->calls->lastReference($prefix);
            $this->numbers[$prefix] = null === $last ? 0 : (int) substr($last, \strlen($prefix));
        }
        $reference = $prefix.str_pad((string) ++$this->numbers[$prefix], 4, '0', \STR_PAD_LEFT);
        $call->setReference($reference);

        return $reference;
    }

    /** The call e-mailed to its co-owner; false without an address. */
    public function send(OwnershipCall $call): bool
    {
        $share = $call->getShare();
        if (null === $share->getEmail()) {
            return false;
        }
        $ownership = $call->getOwnership();
        $organisation = $ownership->getOrganisation();
        $this->mailer->send($share->getEmail(), sprintf('Appel de fonds %s — %s', $call->getReference(), $ownership->getName()), 'ownership_call',
            ['call' => $call, 'share' => $share, 'ownership' => $ownership, 'organisation' => $organisation], $organisation->getEmail());
        $call->setSentAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return true;
    }

    /**
     * Money a co-owner paid on a call: recorded once per reference, posted
     * unless the books have it (a transfer the bank reconciliation matched).
     */
    public function recordPayment(OwnershipCall $call, int $amount, ?\DateTimeImmutable $paidOn = null, ?string $reference = null, bool $post = true): OwnershipPayment
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Un versement est d\'un montant positif.');
        }
        if ($reference && $existing = $this->entityManager->getRepository(OwnershipPayment::class)->findOneBy(['reference' => $reference])) {
            return $existing;
        }
        $payment = new OwnershipPayment($call, $amount, $paidOn, $reference);
        $this->entityManager->persist($payment);
        $ownership = $call->getOwnership();
        $this->log->log($ownership->getOrganisation(), ActivityKind::OTHER, sprintf('%s : versement de %s (%s)', $ownership->getName(), $call->getShare()->getName(), $call->getReference()), $call->getInvoice()?->getUnit(), $amount, $payment->getPaidOn()->setTime(12, 0));
        $this->entityManager->flush();
        if ($post) {
            $this->books?->paymentReceived($payment);
        }

        return $payment;
    }

    /**
     * A rent received for a home of a shared ownership: the co-owners'
     * parts, by the shares that day, posted to their accounts. Nothing
     * while the activity is off, for a home of no ownership, or on a day
     * the shares do not add up (the ownership's screen says so).
     */
    public function rentReceived(Payment $payment): void
    {
        if (null === $this->books || !$this->isActive() || null === $payment->getId()) {
            return;
        }
        $ownership = $this->ownerships->findForUnit($payment->getRentCall()->getLease()->getUnit());
        if (null === $ownership) {
            return;
        }
        try {
            $parts = $ownership->allocate($payment->getAmount(), $payment->getPaidOn());
        } catch (\InvalidArgumentException) {
            return;
        }
        $this->books->incomeReceived($payment, $ownership, $parts);
    }

    /** A rent payment taken back: the co-owners' parts reversed. */
    public function rentCancelled(Payment $payment): void
    {
        if (null === $this->books || !$this->isActive() || null === $payment->getId()) {
            return;
        }
        if (null !== $ownership = $this->ownerships->findForUnit($payment->getRentCall()->getLease()->getUnit())) {
            $this->books->incomeCancelled($payment, $ownership);
        }
    }

    /**
     * The rents received for the ownerships' homes before their shares were
     * entered, shared out now (each once: the postings carry their source).
     * Returns how many payments were looked at.
     */
    public function catchUpIncome(): int
    {
        $seen = 0;
        foreach ($this->rentPayments() as $payment) {
            $this->rentReceived($payment);
            ++$seen;
        }

        return $seen;
    }

    /** A co-owner's statement for a period (OwnershipStatement). */
    public function statement(Ownership $ownership, string $holder, \DateTimeImmutable $from, \DateTimeImmutable $to): OwnershipStatement
    {
        return OwnershipStatement::of($ownership, $holder, $from, $to,
            $this->entityManager->getRepository(OwnershipInvoice::class)->findBy(['ownership' => $ownership]),
            $this->entityManager->getRepository(OwnershipCall::class)->findBy(['ownership' => $ownership]),
            $this->rentPayments($ownership));
    }

    /** @return list<OwnershipStatement> every co-owner's statement for the period, the company's first */
    public function statements(Ownership $ownership, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $invoices = $this->entityManager->getRepository(OwnershipInvoice::class)->findBy(['ownership' => $ownership]);
        $calls = $this->entityManager->getRepository(OwnershipCall::class)->findBy(['ownership' => $ownership]);
        $rents = $this->rentPayments($ownership);
        $statements = [];
        foreach ($ownership->holders() as $holder => $share) {
            $statements[] = OwnershipStatement::of($ownership, $holder, $from, $to, $invoices, $calls, $rents);
        }
        usort($statements, fn (OwnershipStatement $a, OwnershipStatement $b) => [OwnershipShare::COMPANY !== $a->holder, $a->getName()] <=> [OwnershipShare::COMPANY !== $b->holder, $b->getName()]);

        return $statements;
    }

    public function statementHtml(OwnershipStatement $statement): string
    {
        return $this->twig->render('@Estate/pdf/ownership_statement.html.twig', ['statement' => $statement, 'ownership' => $statement->ownership, 'organisation' => $statement->ownership->getOrganisation()]);
    }

    /** The statement's PDF, or null without dompdf. */
    public function statementPdf(OwnershipStatement $statement): ?string
    {
        return $this->receipts->htmlToPdf($this->statementHtml($statement));
    }

    public function statementFilename(OwnershipStatement $statement): string
    {
        $name = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $statement->getName()) ?: 'indivisaire';

        return sprintf('releve-%s-%s.pdf', trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-'), $statement->getPeriodKey());
    }

    /** The statement e-mailed to its co-owner, the PDF attached; false without an address. */
    public function sendStatement(OwnershipStatement $statement): bool
    {
        $email = $statement->share?->getEmail();
        if (null === $email) {
            return false;
        }
        $organisation = $statement->ownership->getOrganisation();
        $pdf = $this->statementPdf($statement);
        $this->mailer->send($email, sprintf('Relevé %s — %s', $statement->getPeriodLabel(), $statement->ownership->getName()), 'ownership_statement',
            ['statement' => $statement, 'ownership' => $statement->ownership, 'organisation' => $organisation], $organisation->getEmail(),
            null === $pdf ? [] : [$this->statementFilename($statement) => $pdf]);

        return true;
    }

    /** The ownership's cloud folder made and shared with its co-owners (omnibase/drive); its path, or null without the cloud. */
    public function syncFolder(Ownership $ownership): ?string
    {
        try {
            return $this->folders?->sync($ownership);
        } catch (\Throwable $e) {
            $this->logger?->warning('The folder of {name} could not be made: {message}', ['name' => $ownership->getName(), 'message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * The shares an account holds or held - the co-owner's space -, none
     * while the activity is off.
     *
     * @return list<OwnershipShare>
     */
    public function sharesOf(?User $user): array
    {
        return null === $user || !$this->isActive() ? [] : $this->ownerships->sharesOf($user);
    }

    /** @return list<Payment> the rents received for an ownership's homes (every ownership's when null), oldest first */
    public function rentPayments(?Ownership $ownership = null): array
    {
        $units = [];
        foreach (null === $ownership ? $this->ownerships->findAll() : [$ownership] as $o) {
            foreach ($o->getUnits() as $unit) {
                $units[$unit->getId()] = $unit;
            }
        }
        if ([] === $units) {
            return [];
        }

        return $this->entityManager->createQueryBuilder()->select('p', 'r', 'l')->from(Payment::class, 'p')
            ->join('p.rentCall', 'r')->join('r.lease', 'l')
            ->where('l.unit IN (:units)')->setParameter('units', array_values($units))
            ->orderBy('p.paidOn', 'ASC')->addOrderBy('p.id', 'ASC')
            ->getQuery()->getResult();
    }

    private static function euros(int $minor): string
    {
        return number_format($minor / 100, 2, ',', ' ').' €';
    }
}
