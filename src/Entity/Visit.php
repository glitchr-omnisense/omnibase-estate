<?php

namespace Base\Estate\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A buyer's visit of a property on sale: when, and a few words after (the
 * buyer's impression, what the agent noticed). The seller sees the date
 * and those words, never who visited.
 */
#[ORM\Entity]
#[ORM\Table(name: 'estate_visit')]
class Visit
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SaleMandate::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SaleMandate $mandate;

    #[ORM\ManyToOne(targetEntity: Buyer::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Buyer $buyer;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $visitedAt;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $feedback = null;

    public function __construct(?SaleMandate $mandate = null, ?Buyer $buyer = null, ?\DateTimeImmutable $visitedAt = null)
    {
        if ($mandate) {
            $this->mandate = $mandate;
        }
        if ($buyer) {
            $this->buyer = $buyer;
        }
        $this->visitedAt = $visitedAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getMandate(): SaleMandate { return $this->mandate; }
    public function setMandate(SaleMandate $mandate): self { $this->mandate = $mandate; return $this; }
    public function getBuyer(): Buyer { return $this->buyer; }
    public function setBuyer(Buyer $buyer): self { $this->buyer = $buyer; return $this; }
    public function getVisitedAt(): \DateTimeImmutable { return $this->visitedAt; }
    public function setVisitedAt(?\DateTimeImmutable $visitedAt): self { $this->visitedAt = $visitedAt ?? new \DateTimeImmutable(); return $this; }
    public function getFeedback(): ?string { return $this->feedback; }
    public function setFeedback(?string $feedback): self { $this->feedback = '' !== trim((string) $feedback) ? trim((string) $feedback) : null; return $this; }
}
