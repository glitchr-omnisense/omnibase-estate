<?php

namespace Base\Estate\Service;

use Base\Entity\User;
use Base\Estate\Drive\MandatorFolders;
use Base\Estate\Entity\Mandate;
use Base\Estate\Entity\MandateExpense;
use Base\Estate\Entity\MandateFee;
use Base\Estate\Entity\MandatePayout;
use Base\Estate\Entity\Mandator;
use Base\Estate\Entity\Payment;
use Base\Estate\Entity\RentCall;
use Base\Estate\Entity\Unit;
use Base\Estate\Entity\WorkOrder;
use Base\Estate\Enum\Activity;
use Base\Estate\Enum\ActivityKind;
use Base\Estate\Ledger\ManagementBooks;
use Base\Estate\Model\MandateStatement;
use Base\Estate\Model\Period;
use Base\Estate\Repository\MandatePayoutRepository;
use Base\Estate\Repository\MandateRepository;
use Base\Estate\Repository\RentCallRepository;
use Doctrine\ORM\EntityManagerInterface;
use Omnibank\Files\Pain001Writer;
use Omnibank\Model\Money;
use Omnibank\Model\Transfer;
use Psr\Log\LoggerInterface;
use Twig\Environment;

/**
 * Property management for third parties (the `management` activity): the
 * homes owners entrust to the agency under a mandate, their rents
 * collected for them, the agency's fee on each, the expenses paid for
 * them, their statement (compte rendu de gérance) and their payouts - the
 * amount due at a period's end, a SEPA credit transfer file (pain.001) for
 * the bank, paid once the bank line is reconciled. The books are
 * omnibase/ledger's (Ledger\ManagementBooks), the owner's folder
 * omnibase/drive's (Drive\MandatorFolders), the SEPA file omnibank/files'
 * (Pain001Writer): all optional.
 *
 * Nothing here reads the management tables while the site does not run
 * the activity: mandateFor(), rentReceived(), mandatorsOf() answer nothing
 * then.
 */
class Management
{
    /** @var array<string, int> the last number given per prefix in this request, ahead of the flush */
    private array $numbers = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Activities $activities,
        private readonly MandateRepository $mandates,
        private readonly MandatePayoutRepository $payouts,
        private readonly RentCallRepository $rentCallRepository,
        private readonly EstateMailer $mailer,
        private readonly Receipts $receipts,
        private readonly Environment $twig,
        private readonly ActivityLog $log,
        private readonly ?ManagementBooks $books = null,
        private readonly ?MandatorFolders $folders = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function isActive(): bool
    {
        return $this->activities->has(Activity::MANAGEMENT);
    }

    public function hasBooks(): bool
    {
        return null !== $this->books;
    }

    public function hasCloud(): bool
    {
        return null !== $this->folders;
    }

    /** Whether payouts can be written as a SEPA file (omnibank/files). */
    public function hasSepa(): bool
    {
        return class_exists(Pain001Writer::class);
    }

    /** The mandate a home is managed under on $on; null while the activity is off. */
    public function mandateFor(Unit $unit, \DateTimeInterface $on): ?Mandate
    {
        if (!$this->isActive() || null === $unit->getId()) {
            return null;
        }

        return $this->mandates->findForUnit($unit, $on);
    }

    /** Where the money of a managed home goes in the books: the escrow account (512 without one), null for a home of the company's own. */
    public function cashAccount(Unit $unit, \DateTimeInterface $on): ?string
    {
        $mandate = null === $this->books ? null : $this->mandateFor($unit, $on);
        $book = null === $mandate ? null : $this->books->bookFor($mandate);

        return null === $book ? null : $this->books->cashAccount($book);
    }

    /** "MG-2026-0001": the year it starts, in the order mandates are saved (the register of mandates). */
    public function number(Mandate $mandate): string
    {
        if (null !== $mandate->getNumber()) {
            return $mandate->getNumber();
        }
        $prefix = 'MG-'.$mandate->getStartsOn()->format('Y').'-';
        $mandate->setNumber($prefix.$this->next($prefix, fn () => $this->mandates->lastNumber($prefix)));

        return $mandate->getNumber();
    }

    /** A rent called for a managed home, in the books: owed by the tenant, for the owner. False for a home of the company's own. */
    public function rentCalled(RentCall $call): bool
    {
        $mandate = $this->mandateFor($call->getLease()->getUnit(), $call->getPeriod());
        if (null === $mandate) {
            return false;
        }
        $this->books?->rentCalled($call, $mandate);

        return true;
    }

    /**
     * A rent collected for a managed home: the owner's (posted to their
     * account) and the agency's fee on it - recorded and posted. Once per
     * payment; nothing while the activity is off or for a home of the
     * company's own.
     */
    public function rentReceived(Payment $payment): ?MandateFee
    {
        if (!$this->isActive() || null === $payment->getId()) {
            return null;
        }
        $call = $payment->getRentCall();
        $unit = $call->getLease()->getUnit();
        $mandate = $this->mandateFor($unit, $payment->getPaidOn());
        if (null === $mandate) {
            return null;
        }
        if (null !== $fee = $this->entityManager->getRepository(MandateFee::class)->findOneBy(['payment' => $payment])) {
            return $fee;
        }

        [$amount, $vat, $code] = $this->fee($mandate, $payment->getAmount());
        $fee = (new MandateFee($mandate, $payment->getPaidOn(), sprintf('Loyer %s — %s', $call->getPeriod()->format('m/Y'), $unit->getName()), $payment->getAmount(), $mandate->getFeeRate(), $amount, $vat, $code))
            ->setPayment($payment)->setUnit($unit);
        $this->entityManager->persist($fee);
        $this->entityManager->flush();

        $this->books?->rentCollected($payment, $mandate);
        $this->books?->feeCharged($fee);

        return $fee;
    }

    /** A rent payment taken back: the owner's credit and the fee reversed, the fee gone. */
    public function rentCancelled(Payment $payment): void
    {
        if (!$this->isActive() || null === $payment->getId()) {
            return;
        }
        $fee = $this->entityManager->getRepository(MandateFee::class)->findOneBy(['payment' => $payment]);
        if (null === $fee) {
            return;
        }
        $this->books?->rentCancelled($payment, $fee->getMandate());
        $this->books?->feeCancelled($fee);
        $this->entityManager->remove($fee);
        $this->entityManager->flush();
    }

    /**
     * The fee on an amount collected: the mandate's rate before tax, rounded
     * to the cent half up, and its VAT when the agency's book is liable.
     *
     * @return array{0: int, 1: int, 2: ?string} before tax, VAT, the VAT code
     */
    public function fee(Mandate $mandate, int $collected): array
    {
        $amount = intdiv(2 * $collected * $mandate->getFeeRate() + 10000, 20000);
        $tax = $this->books?->feeTax($mandate);
        if (null === $tax || $amount <= 0) {
            return [$amount, 0, null];
        }

        return [$amount, $tax->split($amount, false)[1], $tax->getCode()];
    }

    /** A work order's invoice on a managed home, charged to its owner when the work order is closed; null for a home of the company's own. */
    public function workInvoiced(WorkOrder $order): ?MandateExpense
    {
        if (!$order->getInvoiceAmount() || null === $order->getId()) {
            return null;
        }
        $date = $order->getClosedAt() ?? new \DateTimeImmutable('today');
        $mandate = $this->mandateFor($order->getUnit(), $date);
        if (null === $mandate) {
            return null;
        }
        $expense = $this->entityManager->getRepository(MandateExpense::class)->findOneBy(['workOrder' => $order]);
        if (null === $expense) {
            $expense = (new MandateExpense($mandate, sprintf('Travaux — %s', $order->getTitle()), $order->getInvoiceAmount(), $date))
                ->setUnit($order->getUnit())->setWorkOrder($order)->setContractor($order->getContractor())->setNumber($order->getInvoiceNumber());
            $this->entityManager->persist($expense);
            $this->entityManager->flush();
        }
        $this->postExpense($expense);

        return $expense;
    }

    /** Whether a work order's invoice is the owner's, not the company's: the books leave it to workInvoiced(). */
    public function isManaged(Unit $unit, \DateTimeInterface $on): bool
    {
        return null !== $this->mandateFor($unit, $on);
    }

    /** An expense charged to the owner's account: posted once. */
    public function postExpense(MandateExpense $expense): void
    {
        if ($expense->isPosted() || $expense->getAmount() <= 0) {
            return;
        }
        if (null === $expense->getId()) {
            $this->entityManager->persist($expense);
            $this->entityManager->flush();
        }
        $expense->markPosted();
        $this->entityManager->flush();
        $this->books?->expenseCharged($expense);
        $mandate = $expense->getMandate();
        $this->log->log($mandate->getOrganisation(), ActivityKind::OTHER, sprintf('Gérance %s : dépense « %s » imputée', $mandate->getMandator()->getName(), $expense->getLabel()), $expense->getUnit(), -$expense->getAmount());
        $this->entityManager->flush();
    }

    /** An owner's statement for a period (MandateStatement). */
    public function statement(Mandate $mandate, \DateTimeImmutable $from, \DateTimeImmutable $to): MandateStatement
    {
        return MandateStatement::of($mandate, $from, $to,
            $this->rentCalls($mandate),
            $this->entityManager->getRepository(MandateFee::class)->findBy(['mandate' => $mandate], ['date' => 'ASC', 'id' => 'ASC']),
            $this->entityManager->getRepository(MandateExpense::class)->findBy(['mandate' => $mandate], ['date' => 'ASC', 'id' => 'ASC']),
            $this->entityManager->getRepository(MandatePayout::class)->findBy(['mandate' => $mandate], ['issuedOn' => 'ASC', 'id' => 'ASC']));
    }

    /** @return list<MandateStatement> each mandate running in the period's statement, by owner */
    public function statements(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return array_map(fn (Mandate $mandate) => $this->statement($mandate, $from, $to), $this->mandates->inForce($from, $to));
    }

    /** The owner's balance at the end of $on: what the agency holds for them. */
    public function balance(Mandate $mandate, \DateTimeImmutable $on): int
    {
        return $this->statement($mandate, $on, $on)->getClosing();
    }

    public function statementHtml(MandateStatement $statement): string
    {
        return $this->twig->render('@Estate/pdf/mandate_statement.html.twig', ['statement' => $statement, 'mandate' => $statement->mandate, 'mandator' => $statement->mandate->getMandator(), 'organisation' => $statement->mandate->getOrganisation()]);
    }

    /** The statement's PDF, or null without dompdf. */
    public function statementPdf(MandateStatement $statement): ?string
    {
        return $this->receipts->htmlToPdf($this->statementHtml($statement));
    }

    public function statementFilename(MandateStatement $statement): string
    {
        $name = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $statement->getName()) ?: 'proprietaire';

        return sprintf('compte-rendu-%s-%s.pdf', trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-'), $statement->getPeriodKey());
    }

    /**
     * The statement e-mailed to its owner, the PDF attached and put in their
     * cloud folder; the mandate marks the period sent. False without an address.
     */
    public function sendStatement(MandateStatement $statement): bool
    {
        $mandator = $statement->mandate->getMandator();
        if (null === $mandator->getEmail()) {
            return false;
        }
        $organisation = $statement->mandate->getOrganisation();
        $pdf = $this->statementPdf($statement);
        $this->mailer->send($mandator->getEmail(), sprintf('Compte rendu de gérance %s — %s', $statement->getPeriodLabel(), $organisation->getName()), 'mandate_statement',
            ['statement' => $statement, 'mandate' => $statement->mandate, 'mandator' => $mandator, 'organisation' => $organisation], $organisation->getEmail(),
            null === $pdf ? [] : [$this->statementFilename($statement) => $pdf]);
        $statement->mandate->markStatementSent($statement->getPeriodKey());
        $this->entityManager->flush();
        if (null !== $pdf && null !== $this->folders) {
            try {
                $this->folders->fileStatement($mandator, $this->statementFilename($statement), $pdf);
            } catch (\Throwable $e) {
                // The cloud never stops a statement: it went by e-mail.
                $this->logger?->warning('The statement of {name} did not reach their folder: {message}', ['name' => $mandator->getName(), 'message' => $e->getMessage()]);
            }
        }

        return true;
    }

    /**
     * The mandates whose last closed period's statement has not gone out:
     * the statements to send.
     *
     * @return list<array{mandate: Mandate, period: string}>
     */
    public function statementsToSend(?\DateTimeImmutable $today = null): array
    {
        if (!$this->isActive()) {
            return [];
        }
        $today ??= new \DateTimeImmutable('today');
        $due = [];
        foreach ($this->mandates->inForce($today->modify('-1 year'), $today) as $mandate) {
            $period = $mandate->getFrequency()->lastClosed($today);
            [$from, $to] = Period::parse($period);
            if ($mandate->overlaps($from, $to) && !$mandate->isStatementSent($period)) {
                $due[] = ['mandate' => $mandate, 'period' => $period];
            }
        }

        return $due;
    }

    /**
     * What an owner is due at $on: their balance that day, less the payouts
     * already issued on it (after that day, for a balance taken by then) and
     * the mandate's reserve.
     *
     * @return array{balance: int, reserve: int, amount: int}
     */
    public function available(Mandate $mandate, \DateTimeImmutable $on): array
    {
        $balance = $this->balance($mandate, $on);
        foreach ($this->entityManager->getRepository(MandatePayout::class)->findBy(['mandate' => $mandate]) as $payout) {
            if ($payout->getIssuedOn()->format('Y-m-d') > $on->format('Y-m-d') && $payout->getBalanceOn()->format('Y-m-d') <= $on->format('Y-m-d')) {
                $balance -= $payout->getAmount();
            }
        }

        return ['balance' => $balance, 'reserve' => max(0, min($balance, $mandate->getReserve())), 'amount' => max(0, $balance - $mandate->getReserve())];
    }

    /**
     * What each owner is due at $on (available()): the mandates running
     * then, those with something to pay out.
     *
     * @return list<array{mandate: Mandate, balance: int, reserve: int, amount: int}>
     */
    public function payoutsDue(\DateTimeImmutable $on): array
    {
        if (!$this->isActive()) {
            return [];
        }
        $due = [];
        foreach ($this->mandates->inForce($on->modify('-1 year'), $on) as $mandate) {
            $available = $this->available($mandate, $on);
            if ($available['amount'] > 0) {
                $due[] = ['mandate' => $mandate] + $available;
            }
        }

        return $due;
    }

    /**
     * A payout to an owner, issued: what they are due at $on (available()),
     * or $amount, numbered RV-<year>-<n>, taken off their account.
     *
     * @throws \InvalidArgumentException nothing to pay out
     */
    public function issuePayout(Mandate $mandate, \DateTimeImmutable $on, ?int $amount = null, ?\DateTimeImmutable $issuedOn = null): MandatePayout
    {
        $available = $this->available($mandate, $on);
        $amount ??= $available['amount'];
        if ($amount <= 0) {
            throw new \InvalidArgumentException(sprintf('Rien à reverser à %s au %s.', $mandate->getMandator()->getName(), $on->format('d/m/Y')));
        }
        $payout = new MandatePayout($mandate, $on, $available['balance'], max(0, $available['balance'] - $amount), $amount, $issuedOn ?? max($on->modify('+1 day'), new \DateTimeImmutable('today')));
        $prefix = 'RV-'.$payout->getIssuedOn()->format('Y').'-';
        $payout->setReference($prefix.$this->next($prefix, fn () => $this->payouts->lastReference($prefix)));
        $this->entityManager->persist($payout);
        $this->entityManager->flush();
        $this->books?->payoutIssued($payout);
        $this->log->log($mandate->getOrganisation(), ActivityKind::OTHER, sprintf('Gérance %s : reversement %s', $mandate->getMandator()->getName(), $payout->getReference()), null, -$amount);
        $this->entityManager->flush();

        return $payout;
    }

    /**
     * The payouts due at $on, issued (payoutsDue()).
     *
     * @return list<MandatePayout>
     */
    public function issuePayouts(\DateTimeImmutable $on): array
    {
        $issued = [];
        foreach ($this->payoutsDue($on) as $due) {
            $issued[] = $this->issuePayout($due['mandate'], $on, $due['amount']);
        }

        return $issued;
    }

    /**
     * The payouts as a SEPA credit transfer file (pain.001.001.03): from the
     * agency's escrow account to each owner's IBAN, the payout's reference as
     * the end-to-end id (the bank line carries it back). The payouts are
     * marked exported.
     *
     * @param list<MandatePayout> $payouts
     *
     * @throws \InvalidArgumentException no SEPA writer, no payout, payouts of several agencies, an IBAN missing
     */
    public function sepa(array $payouts, ?\DateTimeImmutable $executionDate = null, ?\DateTimeImmutable $now = null): string
    {
        if (!$this->hasSepa()) {
            throw new \InvalidArgumentException('Le fichier SEPA demande omnibank/files.');
        }
        $payouts = array_values(array_filter($payouts, fn (MandatePayout $payout) => !$payout->isPaid()));
        if ([] === $payouts) {
            throw new \InvalidArgumentException('Aucun reversement à virer.');
        }
        $organisation = $payouts[0]->getMandate()->getOrganisation();
        $transfers = [];
        foreach ($payouts as $payout) {
            if ($payout->getMandate()->getOrganisation() !== $organisation) {
                throw new \InvalidArgumentException('Un fichier SEPA part d\'un seul compte : les reversements d\'une seule société à la fois.');
            }
            $book = $this->books?->bookFor($payout->getMandate());
            $debtorIban = (null === $book ? null : $this->books->escrowIban($book)) ?? $organisation->getIban();
            if (null === $debtorIban) {
                throw new \InvalidArgumentException(sprintf('%s n\'a ni compte séquestre ni IBAN : d\'où partent les virements ?', $organisation->getName()));
            }
            if (null === $payout->getIban()) {
                throw new \InvalidArgumentException(sprintf('%s n\'a pas d\'IBAN.', $payout->getMandator()->getName()));
            }
            $transfers[] = new Transfer($debtorIban, $payout->getMandator()->getName(), $payout->getIban(), $payout->getMandator()->getBic(), Money::of($payout->getAmount(), 'EUR'),
                sprintf('Reversement de gérance %s au %s', $payout->getReference(), $payout->getBalanceOn()->format('d/m/Y')), $payout->getReference(), $executionDate);
        }
        $now ??= new \DateTimeImmutable();
        $writer = new Pain001Writer($organisation->getLegalName() ?: $organisation->getName());
        try {
            $xml = $writer->write($transfers, 'RV-'.$now->format('YmdHis'), $now);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }
        foreach ($payouts as $payout) {
            $payout->markExported($now);
        }
        $this->entityManager->flush();

        return $xml;
    }

    /** A payout paid: posted from the escrow account unless the books have it (a bank line reconciled against it). */
    public function markPaid(MandatePayout $payout, ?\DateTimeImmutable $paidOn = null, ?string $reference = null, bool $post = true): void
    {
        if ($payout->isPaid()) {
            return;
        }
        $payout->markPaid($paidOn ?? new \DateTimeImmutable('today'), $reference);
        $this->entityManager->flush();
        if ($post) {
            $this->books?->payoutPaid($payout);
        }
    }

    /**
     * A payout taken back before it is paid (a wrong amount, an IBAN to
     * fix): its entry reversed, the payout gone; the owner's balance is back.
     *
     * @throws \InvalidArgumentException it is paid
     */
    public function cancelPayout(MandatePayout $payout): void
    {
        if ($payout->isPaid()) {
            throw new \InvalidArgumentException(sprintf('%s est payé : il reste.', $payout->getReference()));
        }
        $this->books?->payoutCancelled($payout);
        $this->entityManager->remove($payout);
        $this->entityManager->flush();
    }

    /**
     * The rents collected for managed homes before the activity, a mandate or
     * its fees were entered, taken now (each once). Returns how many payments
     * were looked at.
     */
    public function catchUp(): int
    {
        if (!$this->isActive()) {
            return 0;
        }
        $seen = 0;
        foreach ($this->mandates->findAll() as $mandate) {
            foreach ($this->rentCalls($mandate) as $call) {
                foreach ($call->getPayments() as $payment) {
                    if ($mandate->covers($payment->getPaidOn())) {
                        $this->rentReceived($payment);
                        ++$seen;
                    }
                }
            }
        }

        return $seen;
    }

    /**
     * The owners an account is - the owner's space -, none while the
     * activity is off.
     *
     * @return list<Mandator>
     */
    public function mandatorsOf(?User $user): array
    {
        return null === $user || !$this->isActive() ? [] : $this->mandates->mandatorsOf($user);
    }

    /** The owner's cloud folder made and shared with them (omnibase/drive); its path, or null without the cloud. */
    public function syncFolder(Mandator $mandator): ?string
    {
        try {
            return $this->folders?->sync($mandator);
        } catch (\Throwable $e) {
            $this->logger?->warning('The folder of {name} could not be made: {message}', ['name' => $mandator->getName(), 'message' => $e->getMessage()]);

            return null;
        }
    }

    /** @return list<RentCall> the rent calls of a mandate's homes, their payments with them, oldest first */
    public function rentCalls(Mandate $mandate): array
    {
        return $this->rentCallRepository->forUnits($mandate->getUnits()->toArray());
    }

    /** The next number after the last one given for a prefix, padded to four digits. */
    private function next(string $prefix, callable $last): string
    {
        if (!isset($this->numbers[$prefix])) {
            $found = $last();
            $this->numbers[$prefix] = null === $found ? 0 : (int) substr($found, \strlen($prefix));
        }

        return str_pad((string) ++$this->numbers[$prefix], 4, '0', \STR_PAD_LEFT);
    }
}
