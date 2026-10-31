<?php

namespace Base\Estate\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Money a co-owner paid on a call for funds: recorded once per reference
 * ("bank:<line>:<call>" for a transfer the bank reconciliation matched).
 */
#[ORM\Entity]
#[ORM\Table(name: 'estate_ownership_payment')]
class OwnershipPayment
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: OwnershipCall::class, inversedBy: 'payments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private OwnershipCall $call;

    #[ORM\Column]
    private int $amount;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $paidOn;

    #[ORM\Column(length: 80, unique: true, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(OwnershipCall $call, int $amount, ?\DateTimeImmutable $paidOn = null, ?string $reference = null)
    {
        $this->call = $call;
        $this->amount = $amount;
        $this->paidOn = ($paidOn ?? new \DateTimeImmutable('today'))->setTime(0, 0);
        $this->reference = $reference;
        $this->createdAt = new \DateTimeImmutable();
        $call->addPayment($this);
    }

    public function __toString(): string { return sprintf('%s · %.2f €', $this->call->getReference(), $this->amount / 100); }
    public function getId(): ?int { return $this->id; }
    public function getCall(): OwnershipCall { return $this->call; }
    public function getAmount(): int { return $this->amount; }
    public function getPaidOn(): \DateTimeImmutable { return $this->paidOn; }
    public function getReference(): ?string { return $this->reference; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
