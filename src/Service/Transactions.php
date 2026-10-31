<?php

namespace Base\Estate\Service;

use Base\Estate\Drive\SaleFolders;
use Base\Estate\Entity\Buyer;
use Base\Estate\Entity\Offer;
use Base\Estate\Entity\Sale;
use Base\Estate\Entity\SaleEvent;
use Base\Estate\Entity\SaleMandate;
use Base\Estate\Entity\Visit;
use Base\Estate\Enum\Activity;
use Base\Estate\Enum\ActivityKind;
use Base\Estate\Enum\FeeBasis;
use Base\Estate\Enum\OfferStatus;
use Base\Estate\Enum\SaleStep;
use Base\Estate\Ledger\TransactionBooks;
use Base\Estate\Model\Period;
use Base\Estate\Model\WithdrawalPeriod;
use Base\Estate\Repository\SaleMandateRepository;
use Base\Estate\Repository\SaleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Sales for third parties (the `transaction` activity): the sale mandates
 * (numbered VT-<year>-<n>, the register of mandates), the buyers and what
 * they look for - matched against the mandates -, their visits and
 * offers, and once an offer is accepted the sale's French steps, one at a
 * time: compromis signed (notary, deposit, the withdrawal period's end
 * computed), withdrawal period over, conditions lifted, acte authentique
 * signed (the fee invoiced: the agency's income only then), file closed;
 * or cancelled with its reason. The books are omnibase/ledger's
 * (Ledger\TransactionBooks), the mandate's folder omnibase/drive's
 * (Drive\SaleFolders): both optional.
 *
 * While the site does not run the activity nothing here reads its tables
 * (matches(), the dashboard's lists answer nothing) and nothing changes
 * (accept(), advance(), cancel()... refuse: \LogicException).
 */
class Transactions
{
    /** @var array<string, int> the last number given per prefix in this request, ahead of the flush */
    private array $numbers = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Activities $activities,
        private readonly SaleMandateRepository $mandates,
        private readonly SaleRepository $sales,
        private readonly ActivityLog $log,
        private readonly ?TransactionBooks $books = null,
        private readonly ?SaleFolders $folders = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function isActive(): bool
    {
        return $this->activities->has(Activity::TRANSACTION);
    }

    public function hasBooks(): bool
    {
        return null !== $this->books;
    }

    public function hasCloud(): bool
    {
        return null !== $this->folders;
    }

    /** "VT-2026-0001": the year it starts, in the order mandates are saved (the register of mandates). */
    public function number(SaleMandate $mandate): string
    {
        if (null !== $mandate->getNumber()) {
            return $mandate->getNumber();
        }
        $prefix = 'VT-'.$mandate->getStartsOn()->format('Y').'-';
        $mandate->setNumber($prefix.$this->next($prefix, fn () => $this->mandates->lastNumber($prefix)));

        return $mandate->getNumber();
    }

    /**
     * The fee on a sale price, as the mandate states it - a percentage of the
     * price or a fixed amount, all taxes included, rounded to the cent half
     * up -, and the VAT in it when the agency's book is liable.
     *
     * @return array{0: int, 1: int, 2: int, 3: ?string} all taxes included, before tax, VAT, the VAT code
     */
    public function fee(SaleMandate $mandate, int $price): array
    {
        $total = FeeBasis::FIXED === $mandate->getFeeBasis() ? $mandate->getFeeAmount() : intdiv(2 * $price * $mandate->getFeeRate() + 10000, 20000);
        $tax = $this->books?->feeTax($mandate);
        if (null === $tax || $total <= 0) {
            return [$total, $total, 0, null];
        }
        [$amount, $vat] = $tax->split($total, true);

        return [$total, $amount, $vat, $tax->getCode()];
    }

    /** @return list<Visit> the mandate's visits, the latest first */
    public function visits(SaleMandate $mandate): array
    {
        return $this->entityManager->getRepository(Visit::class)->findBy(['mandate' => $mandate], ['visitedAt' => 'DESC']);
    }

    /** @return list<Offer> the mandate's offers, the latest first */
    public function offers(SaleMandate $mandate): array
    {
        return $this->entityManager->getRepository(Offer::class)->findBy(['mandate' => $mandate], ['madeOn' => 'DESC', 'id' => 'DESC']);
    }

    /** @return list<Sale> the mandate's sales, a cancelled one and the one after it, oldest first */
    public function salesOf(SaleMandate $mandate): array
    {
        return $this->entityManager->getRepository(Sale::class)->findBy(['mandate' => $mandate], ['acceptedOn' => 'ASC', 'id' => 'ASC']);
    }

    /** The mandate's sale that is not cancelled (going on, or concluded), if any. */
    public function saleOf(SaleMandate $mandate): ?Sale
    {
        foreach (array_reverse($this->salesOf($mandate)) as $sale) {
            if (SaleStep::CANCELLED !== $sale->getStep()) {
                return $sale;
            }
        }

        return null;
    }

    /** The sale an accepted offer began. */
    public function saleOfOffer(Offer $offer): ?Sale
    {
        return $this->entityManager->getRepository(Sale::class)->findOneBy(['offer' => $offer]);
    }

    /**
     * An offer accepted: the sale begins at its price (the counter-offer's
     * when the seller made one), the buyer let into the mandate's folder.
     *
     * @throws \InvalidArgumentException the offer is not waiting for an answer, or the mandate is sold or under offer already
     */
    public function accept(Offer $offer, ?\DateTimeImmutable $on = null, ?string $note = null): Sale
    {
        $this->assertActive();
        if (!$offer->getStatus()->isOpen()) {
            throw new \InvalidArgumentException(sprintf('L\'offre est %s : elle ne peut plus être acceptée.', mb_strtolower($offer->getStatus()->label())));
        }
        if (null !== $current = $this->saleOf($offer->getMandate())) {
            throw new \InvalidArgumentException(sprintf('%s a déjà une vente (%s, %s) : annulez-la d\'abord.', $offer->getMandate()->getNumber(), $current->getBuyer()->getName(), mb_strtolower($current->getStep()->label())));
        }
        $on ??= new \DateTimeImmutable('today');
        $offer->decide(OfferStatus::ACCEPTED, $on);
        $sale = new Sale($offer, $on);
        $sale->addEvent(new SaleEvent(SaleStep::ACCEPTED, $on, $note ?? sprintf('Offre de %s acceptée.', self::euros($sale->getPrice()))));
        $this->entityManager->persist($sale);
        $this->entityManager->flush();
        $this->logStep($sale, SaleStep::ACCEPTED);
        $this->syncFolder($offer->getMandate(), $sale);

        return $sale;
    }

    /** The seller asks another price: the offer stays open at the counter-offer's amount. */
    public function counter(Offer $offer, int $amount, ?\DateTimeImmutable $on = null): void
    {
        $this->decide($offer, OfferStatus::COUNTERED, $on, $amount);
    }

    public function refuse(Offer $offer, ?\DateTimeImmutable $on = null): void
    {
        $this->decide($offer, OfferStatus::REFUSED, $on);
    }

    /** The buyer takes their offer back. */
    public function withdraw(Offer $offer, ?\DateTimeImmutable $on = null): void
    {
        $this->decide($offer, OfferStatus::WITHDRAWN, $on);
    }

    /**
     * The sale moved to its next step on $on, with what that step brings and
     * its document. Only the next step: a step skipped is refused.
     *
     * - COMPROMISE: notary, deposit (posted when the agency holds it),
     *   notifiedOn (the hand delivery or the registered letter's first
     *   presentation, $on by default) - the withdrawal period's end follows
     *   -, loanDeadline, deedPlannedOn;
     * - WITHDRAWAL: only after the period's last day;
     * - CONDITIONS: the conditions lifted (the loan obtained);
     * - DEED: the acte signed - the fee invoiced to whoever the mandate
     *   names and posted, the deposit the agency holds paid to the notary;
     * - CLOSED: the file closed.
     *
     * @param array{notary?: ?string, deposit?: ?int, notifiedOn?: ?\DateTimeImmutable, loanDeadline?: ?\DateTimeImmutable, deedPlannedOn?: ?\DateTimeImmutable} $data
     *
     * @throws \InvalidArgumentException not the next step, the withdrawal period still running, a sale cancelled or closed
     */
    public function advance(Sale $sale, SaleStep $step, ?\DateTimeImmutable $on = null, array $data = [], ?string $note = null, ?File $file = null, ?string $fileName = null): SaleEvent
    {
        $this->assertActive();
        $on = ($on ?? new \DateTimeImmutable('today'))->setTime(0, 0);
        $next = $sale->getStep()->next();
        if (!$sale->getStep()->isLive() || null === $next) {
            throw new \InvalidArgumentException(sprintf('La vente est %s : plus d\'étape.', mb_strtolower($sale->getStep()->label())));
        }
        if ($step !== $next) {
            throw new \InvalidArgumentException(sprintf('Étape suivante : « %s », pas « %s ».', $next->label(), $step->label()));
        }
        $previous = $sale->dateOf($sale->getStep());
        if (null !== $previous && $on < $previous) {
            throw new \InvalidArgumentException(sprintf('« %s » ne peut dater d\'avant l\'étape précédente (%s).', $step->label(), $previous->format('d/m/Y')));
        }

        $mandate = $sale->getMandate();
        switch ($step) {
            case SaleStep::COMPROMISE:
                $notifiedOn = $data['notifiedOn'] ?? $on;
                $sale->setCompromiseSignedOn($on)->setCompromiseNotifiedOn($notifiedOn)
                    ->setWithdrawalEndsOn(WithdrawalPeriod::end($notifiedOn))
                    ->setNotary($data['notary'] ?? null)->setDeposit($data['deposit'] ?? 0)
                    ->setDepositHeldByAgency($mandate->isDepositHeldByAgency())
                    ->setLoanDeadline($data['loanDeadline'] ?? null)->setDeedPlannedOn($data['deedPlannedOn'] ?? null);
                break;
            case SaleStep::WITHDRAWAL:
                if (null !== $sale->getWithdrawalEndsOn() && $on <= $sale->getWithdrawalEndsOn()) {
                    throw new \InvalidArgumentException(sprintf('Le délai de rétractation court jusqu\'au %s inclus.', $sale->getWithdrawalEndsOn()->format('d/m/Y')));
                }
                $sale->setWithdrawalPurgedOn($on);
                break;
            case SaleStep::CONDITIONS:
                $sale->setConditionsLiftedOn($on);
                if (isset($data['deedPlannedOn'])) {
                    $sale->setDeedPlannedOn($data['deedPlannedOn']);
                }
                break;
            case SaleStep::DEED:
                $sale->setDeedSignedOn($on);
                [$total, $amount, $vat, $code] = $this->fee($mandate, $sale->getPrice());
                $prefix = 'FH-'.$on->format('Y').'-';
                $sale->invoice($prefix.$this->next($prefix, fn () => $this->sales->lastInvoiceNumber($prefix)), $total, $amount, $vat, $code, $mandate->getFeePayer());
                break;
            case SaleStep::CLOSED:
                $sale->setClosedOn($on);
                break;
            default:
                break;
        }
        $sale->setStep($step);
        $event = new SaleEvent($step, $on, $note);
        $contents = null;
        if (null !== $file) {
            $fileName ??= $file instanceof UploadedFile ? $file->getClientOriginalName() : $file->getFilename();
            $contents = null !== $this->folders && is_file($file->getPathname()) ? (string) file_get_contents($file->getPathname()) : null;
            $event->setFile($file)->setFileName($fileName);
        }
        $sale->addEvent($event);
        $this->entityManager->persist($event);
        $this->entityManager->flush();

        if (SaleStep::COMPROMISE === $step) {
            $this->books?->depositReceived($sale);
        }
        if (SaleStep::DEED === $step) {
            $this->books?->feeInvoiced($sale);
            $this->books?->depositReleased($sale, $on, 'Versé au notaire');
        }
        $this->logStep($sale, $step);
        if (null !== $contents) {
            $this->fileDocument($mandate, sprintf('%s — %s', $on->format('Y-m-d'), $fileName), $contents);
        }

        return $event;
    }

    /**
     * A sale cancelled before the acte, with its reason: the buyer's
     * withdrawal, a loan refused, a pre-emption... The deposit the agency
     * holds goes back to the buyer, or to the seller when forfeited; the
     * buyer no longer sees the folder; the mandate may take another offer.
     *
     * @throws \InvalidArgumentException concluded (the acte signed), already cancelled, no reason
     */
    public function cancel(Sale $sale, string $reason, ?\DateTimeImmutable $on = null, bool $depositForfeited = false): void
    {
        $this->assertActive();
        if ($sale->getStep()->isConcluded() || SaleStep::CANCELLED === $sale->getStep()) {
            throw new \InvalidArgumentException(sprintf('La vente est %s : elle ne s\'annule plus.', mb_strtolower($sale->getStep()->label())));
        }
        if ('' === $reason = trim($reason)) {
            throw new \InvalidArgumentException('Une annulation dit pourquoi.');
        }
        $on = ($on ?? new \DateTimeImmutable('today'))->setTime(0, 0);
        $held = $sale->getCompromiseSignedOn() && $sale->isDepositHeldByAgency() && $sale->getDeposit() > 0;
        $sale->cancel($on, $reason, $held && $depositForfeited);
        $sale->addEvent($event = new SaleEvent(SaleStep::CANCELLED, $on, $reason));
        $this->entityManager->persist($event);
        $this->entityManager->flush();
        if ($held) {
            $this->books?->depositReleased($sale, $on, $sale->isDepositForfeited() ? 'Versé au vendeur (dépôt acquis)' : 'Restitué à l\'acquéreur');
        }
        $this->logStep($sale, SaleStep::CANCELLED);
        if (null !== $this->folders && null !== $user = $sale->getBuyer()->getUser()) {
            try {
                $this->folders->unshare($sale->getMandate(), $user);
            } catch (\Throwable $e) {
                $this->logger?->warning('The folder of {number} is still shared with its former buyer: {message}', ['number' => $sale->getMandate()->getNumber(), 'message' => $e->getMessage()]);
            }
        }
    }

    /**
     * The mandates a buyer could buy, a short list: running today, neither
     * under offer nor sold, at their budget (5 % above, to negotiate), of
     * their kind, surface and rooms, in their area - what the mandate does
     * not say does not rule it out. The cheapest first.
     *
     * @return list<array{mandate: SaleMandate, reasons: list<string>}>
     */
    public function matches(Buyer $buyer, ?\DateTimeImmutable $today = null, int $limit = 10): array
    {
        if (!$this->isActive()) {
            return [];
        }
        $today ??= new \DateTimeImmutable('today');
        $found = [];
        foreach ($this->entityManager->getRepository(SaleMandate::class)->findAll() as $mandate) {
            if (!$mandate->covers($today) || null !== $this->saleOf($mandate)) {
                continue;
            }
            $reasons = [];
            if ($buyer->getBudget() > 0) {
                if ($mandate->getPrice() > intdiv($buyer->getBudget() * 105, 100)) {
                    continue;
                }
                $reasons[] = $mandate->getPrice() <= $buyer->getBudget() ? 'dans le budget' : 'jusqu\'à 5 % au-dessus du budget : à négocier';
            }
            if (null !== $buyer->getKind() && null !== $mandate->getKind()) {
                if ($buyer->getKind() !== $mandate->getKind()) {
                    continue;
                }
                $reasons[] = 'le type cherché';
            }
            if (null !== $buyer->getMinSurface() && null !== $mandate->getSurface()) {
                if ($mandate->getSurface() < $buyer->getMinSurface()) {
                    continue;
                }
                $reasons[] = sprintf('%s m²', rtrim(rtrim(number_format($mandate->getSurface(), 2, ',', ' '), '0'), ','));
            }
            if (null !== $buyer->getMinRooms() && null !== $mandate->getRooms()) {
                if ($mandate->getRooms() < $buyer->getMinRooms()) {
                    continue;
                }
                $reasons[] = sprintf('%d pièces', $mandate->getRooms());
            }
            if ([] !== $places = $buyer->getPlaces()) {
                $where = mb_strtolower(implode(' ', array_filter([$mandate->getCity(), $mandate->getPostcode(), $mandate->getUnit()?->getDistrict()])));
                if ('' !== $where) {
                    $in = array_values(array_filter($places, fn (string $place) => str_contains($where, $place)));
                    if ([] === $in) {
                        continue;
                    }
                    $reasons[] = $mandate->getCity() ?? $in[0];
                }
            }
            $found[] = ['mandate' => $mandate, 'reasons' => $reasons];
        }
        usort($found, fn (array $a, array $b) => $a['mandate']->getPrice() <=> $b['mandate']->getPrice());

        return \array_slice($found, 0, $limit);
    }

    /** @return list<SaleMandate> the mandates ending between $today and $until with no sale: to renew, or to let go */
    public function ending(\DateTimeImmutable $today, \DateTimeImmutable $until): array
    {
        if (!$this->isActive()) {
            return [];
        }

        return array_values(array_filter($this->mandates->ending($today, $until), fn (SaleMandate $m) => null === $this->saleOf($m)));
    }

    /** @return list<Offer> the offers waiting for the seller's answer (made, countered), the oldest first */
    public function offersWaiting(): array
    {
        if (!$this->isActive()) {
            return [];
        }
        $repository = $this->entityManager->getRepository(Offer::class);
        $offers = [...$repository->findBy(['status' => OfferStatus::MADE]), ...$repository->findBy(['status' => OfferStatus::COUNTERED])];
        usort($offers, fn (Offer $a, Offer $b) => [$a->getMadeOn(), $a->getId()] <=> [$b->getMadeOn(), $b->getId()]);

        return $offers;
    }

    /** @return list<Sale> the sales going on (accepted to the acte, not closed), the soonest next date first */
    public function salesInProgress(): array
    {
        if (!$this->isActive()) {
            return [];
        }
        $sales = array_values(array_filter($this->entityManager->getRepository(Sale::class)->findAll(), fn (Sale $sale) => $sale->getStep()->isLive()));
        usort($sales, fn (Sale $a, Sale $b) => (($a->nextDate()['date'] ?? null)?->format('Y-m-d') ?? '9999') <=> (($b->nextDate()['date'] ?? null)?->format('Y-m-d') ?? '9999'));

        return $sales;
    }

    /**
     * The fees of a quarter, all taxes included: invoiced (the actes signed
     * in it) and expected (the sales going on whose acte is planned in it).
     *
     * @return array{period: string, invoiced: int, expected: int, total: int, count: int}
     */
    public function feesOfQuarter(\DateTimeImmutable $day): array
    {
        $period = $day->format('Y').'-Q'.intdiv((int) $day->format('n') + 2, 3);
        $fees = ['period' => $period, 'invoiced' => 0, 'expected' => 0, 'total' => 0, 'count' => 0];
        if (!$this->isActive()) {
            return $fees;
        }
        [$from, $to] = Period::parse($period);
        $in = fn (?\DateTimeImmutable $date) => null !== $date && $date->format('Y-m-d') >= $from->format('Y-m-d') && $date->format('Y-m-d') <= $to->format('Y-m-d');
        foreach ($this->entityManager->getRepository(Sale::class)->findAll() as $sale) {
            if ($sale->getStep()->isConcluded() && $in($sale->getDeedSignedOn())) {
                $fees['invoiced'] += $sale->getFeeTotal();
                ++$fees['count'];
            } elseif ($sale->getStep()->isLive() && !$sale->getStep()->isConcluded() && $in($sale->getDeedPlannedOn())) {
                $fees['expected'] += $this->fee($sale->getMandate(), $sale->getPrice())[0];
                ++$fees['count'];
            }
        }
        $fees['total'] = $fees['invoiced'] + $fees['expected'];

        return $fees;
    }

    /** The mandate's cloud folder made and shared (omnibase/drive); its path, or null without the cloud. */
    public function syncFolder(SaleMandate $mandate, ?Sale $sale = null): ?string
    {
        if (null === $this->folders) {
            return null;
        }
        try {
            return $this->folders->sync($mandate, $sale ?? $this->saleOf($mandate));
        } catch (\Throwable $e) {
            $this->logger?->warning('The folder of {number} could not be made: {message}', ['number' => $mandate->getNumber(), 'message' => $e->getMessage()]);

            return null;
        }
    }

    private function fileDocument(SaleMandate $mandate, string $filename, string $contents): void
    {
        try {
            $this->folders?->file($mandate, $filename, $contents);
        } catch (\Throwable $e) {
            // The cloud never stops a step: the document is on the sale.
            $this->logger?->warning('A document of {number} did not reach its folder: {message}', ['number' => $mandate->getNumber(), 'message' => $e->getMessage()]);
        }
    }

    private function decide(Offer $offer, OfferStatus $status, ?\DateTimeImmutable $on, ?int $counterAmount = null): void
    {
        $this->assertActive();
        if (!$offer->getStatus()->isOpen()) {
            throw new \InvalidArgumentException(sprintf('L\'offre est %s : elle ne change plus.', mb_strtolower($offer->getStatus()->label())));
        }
        if (OfferStatus::COUNTERED === $status && ($counterAmount ?? 0) <= 0) {
            throw new \InvalidArgumentException('Une contre-proposition a un montant.');
        }
        $offer->decide($status, $on ?? new \DateTimeImmutable('today'), $counterAmount);
        $this->entityManager->flush();
    }

    private function logStep(Sale $sale, SaleStep $step): void
    {
        $mandate = $sale->getMandate();
        $this->log->log($mandate->getOrganisation(), ActivityKind::OTHER, sprintf('Vente %s : %s (%s)', $mandate->getNumber(), mb_strtolower($step->label()), $sale->getBuyer()->getName()), $mandate->getUnit(),
            SaleStep::DEED === $step ? $sale->getFeeTotal() : null);
        $this->entityManager->flush();
    }

    private function assertActive(): void
    {
        if (!$this->isActive()) {
            throw new \LogicException('The site does not run the transaction activity.');
        }
    }

    private static function euros(int $minor): string
    {
        return number_format($minor / 100, 2, ',', ' ').' €';
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
