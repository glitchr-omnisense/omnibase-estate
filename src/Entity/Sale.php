<?php

namespace Base\Estate\Entity;

use Base\Estate\Enum\FeePayer;
use Base\Estate\Enum\SaleStep;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A sale under way, from the offer accepted: the price, the step it has
 * reached (Enum\SaleStep, moved one at a time by Service\Transactions),
 * each step's date and what it brings - the compromis' notary and deposit
 * (séquestre), when it was notified to the buyer and the end of their
 * withdrawal period, the date the loan must be obtained by, the acte's
 * planned and actual dates -, or its cancellation and why. At the acte
 * the agency's fee is set as invoiced (its number, before tax, VAT, who
 * owes it), kept as it was. Each step's documents are its events
 * (Entity\SaleEvent).
 */
#[ORM\Entity(repositoryClass: \Base\Estate\Repository\SaleRepository::class)]
#[ORM\Table(name: 'estate_sale')]
class Sale
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Offer::class)]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private Offer $offer;

    #[ORM\ManyToOne(targetEntity: SaleMandate::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SaleMandate $mandate;

    #[ORM\ManyToOne(targetEntity: Buyer::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Buyer $buyer;

    /** The price agreed, in minor units. */
    #[ORM\Column]
    private int $price = 0;

    #[ORM\Column(length: 16, enumType: SaleStep::class)]
    private SaleStep $step = SaleStep::ACCEPTED;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $acceptedOn;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $compromiseSignedOn = null;

    /** The compromis notified to the buyer (registered letter's first presentation, or hand delivery): the withdrawal period runs from the next day. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $compromiseNotifiedOn = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $withdrawalEndsOn = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $notary = null;

    /** The deposit (dépôt de garantie, séquestre), in minor units. */
    #[ORM\Column]
    private int $deposit = 0;

    /** Held by the agency on its escrow account (posted), or by the notary (recorded only). */
    #[ORM\Column]
    private bool $depositHeldByAgency = false;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $withdrawalPurgedOn = null;

    /** The date the loan must be obtained by (the condition's deadline). */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $loanDeadline = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $conditionsLiftedOn = null;

    /** The acte authentique's date as planned in the compromis. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $deedPlannedOn = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $deedSignedOn = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $closedOn = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $cancelledOn = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $cancelReason = null;

    /** On a cancellation, the deposit goes to the seller (the buyer's fault) rather than back to the buyer. */
    #[ORM\Column]
    private bool $depositForfeited = false;

    /** The fee's invoice, at the acte: FH-2026-0001. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $invoiceNumber = null;

    /** The fee all taxes included, its base before tax, its VAT and code, who owes it - as invoiced. */
    #[ORM\Column]
    private int $feeTotal = 0;

    #[ORM\Column]
    private int $feeAmount = 0;

    #[ORM\Column]
    private int $feeVat = 0;

    #[ORM\Column(length: 12, nullable: true)]
    private ?string $feeTaxCode = null;

    #[ORM\Column(length: 12, nullable: true, enumType: FeePayer::class)]
    private ?FeePayer $feePayer = null;

    /** @var Collection<int, SaleEvent> */
    #[ORM\OneToMany(targetEntity: SaleEvent::class, mappedBy: 'sale', cascade: ['persist'])]
    #[ORM\OrderBy(['date' => 'ASC', 'id' => 'ASC'])]
    private Collection $events;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Offer $offer, ?\DateTimeImmutable $acceptedOn = null)
    {
        $this->offer = $offer;
        $this->mandate = $offer->getMandate();
        $this->buyer = $offer->getBuyer();
        $this->price = $offer->getPrice();
        $this->acceptedOn = ($acceptedOn ?? new \DateTimeImmutable('today'))->setTime(0, 0);
        $this->events = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string { return sprintf('%s — %s', $this->mandate->getNumber() ?? 'Vente', $this->buyer->getName()); }
    public function getId(): ?int { return $this->id; }
    public function getOffer(): Offer { return $this->offer; }
    public function getMandate(): SaleMandate { return $this->mandate; }
    public function getBuyer(): Buyer { return $this->buyer; }
    public function getPrice(): int { return $this->price; }
    public function getStep(): SaleStep { return $this->step; }
    public function setStep(SaleStep $step): self { $this->step = $step; return $this; }
    public function getAcceptedOn(): \DateTimeImmutable { return $this->acceptedOn; }
    public function getCompromiseSignedOn(): ?\DateTimeImmutable { return $this->compromiseSignedOn; }
    public function setCompromiseSignedOn(?\DateTimeImmutable $on): self { $this->compromiseSignedOn = $on?->setTime(0, 0); return $this; }
    public function getCompromiseNotifiedOn(): ?\DateTimeImmutable { return $this->compromiseNotifiedOn; }
    public function setCompromiseNotifiedOn(?\DateTimeImmutable $on): self { $this->compromiseNotifiedOn = $on?->setTime(0, 0); return $this; }
    public function getWithdrawalEndsOn(): ?\DateTimeImmutable { return $this->withdrawalEndsOn; }
    public function setWithdrawalEndsOn(?\DateTimeImmutable $on): self { $this->withdrawalEndsOn = $on?->setTime(0, 0); return $this; }
    public function getNotary(): ?string { return $this->notary; }
    public function setNotary(?string $notary): self { $this->notary = '' !== trim((string) $notary) ? trim((string) $notary) : null; return $this; }
    public function getDeposit(): int { return $this->deposit; }
    public function setDeposit(?int $deposit): self { $this->deposit = max(0, (int) $deposit); return $this; }
    public function isDepositHeldByAgency(): bool { return $this->depositHeldByAgency; }
    public function setDepositHeldByAgency(bool $held): self { $this->depositHeldByAgency = $held; return $this; }
    public function getWithdrawalPurgedOn(): ?\DateTimeImmutable { return $this->withdrawalPurgedOn; }
    public function setWithdrawalPurgedOn(?\DateTimeImmutable $on): self { $this->withdrawalPurgedOn = $on?->setTime(0, 0); return $this; }
    public function getLoanDeadline(): ?\DateTimeImmutable { return $this->loanDeadline; }
    public function setLoanDeadline(?\DateTimeImmutable $on): self { $this->loanDeadline = $on?->setTime(0, 0); return $this; }
    public function getConditionsLiftedOn(): ?\DateTimeImmutable { return $this->conditionsLiftedOn; }
    public function setConditionsLiftedOn(?\DateTimeImmutable $on): self { $this->conditionsLiftedOn = $on?->setTime(0, 0); return $this; }
    public function getDeedPlannedOn(): ?\DateTimeImmutable { return $this->deedPlannedOn; }
    public function setDeedPlannedOn(?\DateTimeImmutable $on): self { $this->deedPlannedOn = $on?->setTime(0, 0); return $this; }
    public function getDeedSignedOn(): ?\DateTimeImmutable { return $this->deedSignedOn; }
    public function setDeedSignedOn(?\DateTimeImmutable $on): self { $this->deedSignedOn = $on?->setTime(0, 0); return $this; }
    public function getClosedOn(): ?\DateTimeImmutable { return $this->closedOn; }
    public function setClosedOn(?\DateTimeImmutable $on): self { $this->closedOn = $on?->setTime(0, 0); return $this; }
    public function getCancelledOn(): ?\DateTimeImmutable { return $this->cancelledOn; }
    public function getCancelReason(): ?string { return $this->cancelReason; }
    public function isDepositForfeited(): bool { return $this->depositForfeited; }
    public function getInvoiceNumber(): ?string { return $this->invoiceNumber; }
    public function getFeeTotal(): int { return $this->feeTotal; }
    public function getFeeAmount(): int { return $this->feeAmount; }
    public function getFeeVat(): int { return $this->feeVat; }
    public function getFeeTaxCode(): ?string { return $this->feeTaxCode; }
    public function getFeePayer(): ?FeePayer { return $this->feePayer; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** @return Collection<int, SaleEvent> */
    public function getEvents(): Collection { return $this->events; }
    public function addEvent(SaleEvent $event): self { if (!$this->events->contains($event)) { $this->events->add($event); $event->setSale($this); } return $this; }

    /** The fee as invoiced at the acte. */
    public function invoice(string $number, int $total, int $amount, int $vat, ?string $taxCode, FeePayer $payer): self
    {
        $this->invoiceNumber = $number;
        $this->feeTotal = $total;
        $this->feeAmount = $amount;
        $this->feeVat = $vat;
        $this->feeTaxCode = $taxCode;
        $this->feePayer = $payer;

        return $this;
    }

    public function cancel(\DateTimeImmutable $on, string $reason, bool $depositForfeited = false): self
    {
        $this->step = SaleStep::CANCELLED;
        $this->cancelledOn = $on->setTime(0, 0);
        $this->cancelReason = $reason;
        $this->depositForfeited = $depositForfeited;

        return $this;
    }

    /** The date a step was reached, null when it was not (yet). */
    public function dateOf(SaleStep $step): ?\DateTimeImmutable
    {
        return match ($step) {
            SaleStep::ACCEPTED => $this->acceptedOn,
            SaleStep::COMPROMISE => $this->compromiseSignedOn,
            SaleStep::WITHDRAWAL => $this->withdrawalPurgedOn,
            SaleStep::CONDITIONS => $this->conditionsLiftedOn,
            SaleStep::DEED => $this->deedSignedOn,
            SaleStep::CLOSED => $this->closedOn,
            SaleStep::CANCELLED => $this->cancelledOn,
        };
    }

    /**
     * What comes next and when: the end of the withdrawal period, the loan's
     * deadline, the acte's planned date. Null when nothing is dated.
     *
     * @return array{step: SaleStep, date: ?\DateTimeImmutable}|null
     */
    public function nextDate(): ?array
    {
        $next = $this->step->next();
        if (null === $next || !$this->step->isLive()) {
            return null;
        }
        $date = match ($next) {
            SaleStep::WITHDRAWAL => $this->withdrawalEndsOn,
            SaleStep::CONDITIONS => $this->loanDeadline ?? $this->deedPlannedOn,
            SaleStep::DEED => $this->deedPlannedOn,
            default => null,
        };

        return ['step' => $next, 'date' => $date];
    }
}
