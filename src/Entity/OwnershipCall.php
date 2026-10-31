<?php

namespace Base\Estate\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A call for funds to one co-owner: what for, how much, by when, numbered
 * per year ("AF-2026-0001", the reference their transfer quotes). A shared
 * invoice's part is one (it carries the invoice); a provision is called
 * by hand. Sent by e-mail; paid by transfer - the bank reconciliation
 * matches it, as it matches rents - or recorded by hand.
 */
#[ORM\Entity(repositoryClass: \Base\Estate\Repository\OwnershipCallRepository::class)]
#[ORM\Table(name: 'estate_ownership_call')]
#[ORM\Index(fields: ['dueOn'])]
class OwnershipCall
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Ownership::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Ownership $ownership;

    /** The co-owner's share it was called on. */
    #[ORM\ManyToOne(targetEntity: OwnershipShare::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private OwnershipShare $share;

    /** The shared invoice it is a part of; null: a provision called by hand. */
    #[ORM\ManyToOne(targetEntity: OwnershipInvoice::class, inversedBy: 'calls')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?OwnershipInvoice $invoice = null;

    #[ORM\Column(length: 30, unique: true, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(length: 160)]
    private string $label = '';

    #[ORM\Column]
    private int $amount = 0;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $issuedOn;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dueOn;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    /** @var Collection<int, OwnershipPayment> */
    #[ORM\OneToMany(targetEntity: OwnershipPayment::class, mappedBy: 'call', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['paidOn' => 'ASC', 'id' => 'ASC'])]
    private Collection $payments;

    public function __construct(?OwnershipShare $share = null, string $label = '', int $amount = 0, ?\DateTimeImmutable $dueOn = null, ?\DateTimeImmutable $issuedOn = null)
    {
        if ($share) {
            $this->setShare($share);
        }
        $this->label = $label;
        $this->amount = $amount;
        $this->issuedOn = $issuedOn ?? new \DateTimeImmutable('today');
        $this->dueOn = $dueOn ?? $this->issuedOn->modify('+30 days');
        $this->payments = new ArrayCollection();
    }

    public function __toString(): string { return trim(($this->reference ?? '').' '.(isset($this->share) ? $this->share->getName() : '')); }
    public function getId(): ?int { return $this->id; }
    public function getOwnership(): Ownership { return $this->ownership; }
    public function setOwnership(Ownership $ownership): self { $this->ownership = $ownership; return $this; }
    public function getShare(): OwnershipShare { return $this->share; }
    public function setShare(OwnershipShare $share): self { $this->share = $share; $this->ownership = $share->getOwnership(); return $this; }
    public function getInvoice(): ?OwnershipInvoice { return $this->invoice; }
    public function setInvoice(?OwnershipInvoice $invoice): self { $this->invoice = $invoice; $invoice?->addCall($this); return $this; }
    public function getReference(): ?string { return $this->reference; }
    public function setReference(?string $reference): self { $this->reference = $reference; return $this; }
    public function getLabel(): string { return $this->label; }
    public function setLabel(?string $label): self { $this->label = trim((string) $label); return $this; }
    public function getAmount(): int { return $this->amount; }
    public function setAmount(?int $amount): self { $this->amount = max(0, (int) $amount); return $this; }
    public function getIssuedOn(): \DateTimeImmutable { return $this->issuedOn; }
    public function setIssuedOn(?\DateTimeImmutable $issuedOn): self { $this->issuedOn = $issuedOn ?? new \DateTimeImmutable('today'); return $this; }
    public function getDueOn(): \DateTimeImmutable { return $this->dueOn; }
    public function setDueOn(?\DateTimeImmutable $dueOn): self { $this->dueOn = $dueOn ?? $this->issuedOn->modify('+30 days'); return $this; }
    public function getSentAt(): ?\DateTimeImmutable { return $this->sentAt; }
    public function setSentAt(?\DateTimeImmutable $sentAt): self { $this->sentAt = $sentAt; return $this; }
    /** @return Collection<int, OwnershipPayment> */
    public function getPayments(): Collection { return $this->payments; }
    public function getHolder(): string { return $this->share->holder(); }

    public function addPayment(OwnershipPayment $payment): self
    {
        if (!$this->payments->contains($payment)) {
            $this->payments->add($payment);
        }

        return $this;
    }

    public function removePayment(OwnershipPayment $payment): self
    {
        $this->payments->removeElement($payment);

        return $this;
    }

    public function getPaid(): int
    {
        return array_sum(array_map(fn (OwnershipPayment $p) => $p->getAmount(), $this->payments->toArray()));
    }

    public function getRemaining(): int { return max(0, $this->amount - $this->getPaid()); }
    public function isPaid(): bool { return $this->amount > 0 && $this->getRemaining() <= 0; }

    public function isLate(?\DateTimeImmutable $today = null): bool
    {
        return !$this->isPaid() && $this->dueOn < ($today ?? new \DateTimeImmutable('today'));
    }

    /** called, partial, paid or late. */
    public function getStatus(?\DateTimeImmutable $today = null): string
    {
        return match (true) {
            $this->isPaid() => 'paid',
            $this->isLate($today) => 'late',
            $this->getPaid() > 0 => 'partial',
            default => 'called',
        };
    }
}
