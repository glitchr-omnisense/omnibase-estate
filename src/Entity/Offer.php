<?php

namespace Base\Estate\Entity;

use Base\Estate\Enum\OfferStatus;
use Doctrine\ORM\Mapping as ORM;

/**
 * A buyer's offer on a property on sale (offre d'achat): the amount, its
 * conditions - a loan (condition suspensive d'obtention de prêt, of an
 * amount), until when it holds, anything else -, and where it stands
 * (Enum\OfferStatus). Accepted, the sale begins (Entity\Sale).
 */
#[ORM\Entity]
#[ORM\Table(name: 'estate_offer')]
class Offer
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SaleMandate::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SaleMandate $mandate;

    #[ORM\ManyToOne(targetEntity: Buyer::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Buyer $buyer;

    /** The price offered, in minor units. */
    #[ORM\Column]
    private int $amount = 0;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $madeOn;

    /** Subject to a loan (condition suspensive d'obtention de prêt). */
    #[ORM\Column]
    private bool $loanCondition = false;

    #[ORM\Column(nullable: true)]
    private ?int $loanAmount = null;

    /** Until when the offer holds; past it, the buyer is free. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $validUntil = null;

    /** Its other conditions, as written. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $conditions = null;

    #[ORM\Column(length: 12, enumType: OfferStatus::class)]
    private OfferStatus $status = OfferStatus::MADE;

    /** The price the seller asks back (a counter-offer). */
    #[ORM\Column(nullable: true)]
    private ?int $counterAmount = null;

    /** When it was accepted, refused or withdrawn. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $decidedOn = null;

    public function __construct(?SaleMandate $mandate = null, ?Buyer $buyer = null, int $amount = 0, ?\DateTimeImmutable $madeOn = null)
    {
        if ($mandate) {
            $this->mandate = $mandate;
        }
        if ($buyer) {
            $this->buyer = $buyer;
        }
        $this->amount = $amount;
        $this->madeOn = ($madeOn ?? new \DateTimeImmutable('today'))->setTime(0, 0);
    }

    public function __toString(): string { return sprintf('Offre de %s €', number_format($this->amount / 100, 0, ',', ' ')); }
    public function getId(): ?int { return $this->id; }
    public function getMandate(): SaleMandate { return $this->mandate; }
    public function setMandate(SaleMandate $mandate): self { $this->mandate = $mandate; return $this; }
    public function getBuyer(): Buyer { return $this->buyer; }
    public function setBuyer(Buyer $buyer): self { $this->buyer = $buyer; return $this; }
    public function getAmount(): int { return $this->amount; }
    public function setAmount(?int $amount): self { $this->amount = max(0, (int) $amount); return $this; }
    public function getMadeOn(): \DateTimeImmutable { return $this->madeOn; }
    public function setMadeOn(?\DateTimeImmutable $madeOn): self { $this->madeOn = ($madeOn ?? new \DateTimeImmutable('today'))->setTime(0, 0); return $this; }
    public function isLoanCondition(): bool { return $this->loanCondition; }
    public function setLoanCondition(?bool $loanCondition): self { $this->loanCondition = (bool) $loanCondition; return $this; }
    public function getLoanAmount(): ?int { return $this->loanAmount; }
    public function setLoanAmount(?int $loanAmount): self { $this->loanAmount = $loanAmount > 0 ? $loanAmount : null; return $this; }
    public function getValidUntil(): ?\DateTimeImmutable { return $this->validUntil; }
    public function setValidUntil(?\DateTimeImmutable $validUntil): self { $this->validUntil = $validUntil?->setTime(0, 0); return $this; }
    public function getConditions(): ?string { return $this->conditions; }
    public function setConditions(?string $conditions): self { $this->conditions = '' !== trim((string) $conditions) ? trim((string) $conditions) : null; return $this; }
    public function getStatus(): OfferStatus { return $this->status; }
    public function getCounterAmount(): ?int { return $this->counterAmount; }
    public function getDecidedOn(): ?\DateTimeImmutable { return $this->decidedOn; }

    /** The price the sale would be at: the counter-offer's when the seller made one. */
    public function getPrice(): int { return $this->counterAmount ?? $this->amount; }

    /** Its state changes through Service\Transactions (accept, counter, refuse, withdraw). */
    public function decide(OfferStatus $status, \DateTimeImmutable $on, ?int $counterAmount = null): self
    {
        $this->status = $status;
        if (OfferStatus::COUNTERED === $status) {
            $this->counterAmount = $counterAmount;
            $this->decidedOn = null;
        } else {
            $this->decidedOn = $on->setTime(0, 0);
        }

        return $this;
    }
}
