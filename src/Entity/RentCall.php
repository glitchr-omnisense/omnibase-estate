<?php

namespace Base\Estate\Entity;

use Base\Estate\Enum\RentStatus;
use Base\Estate\Repository\RentCallRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A month's rent asked of a lease (the avis d'échéance): rent and charges,
 * due on the lease's day, settled by payments. Paid in full, its receipt
 * (quittance) is numbered and can be downloaded.
 */
#[ORM\Entity(repositoryClass: RentCallRepository::class)]
#[ORM\Table(name: 'estate_rent_call')]
#[ORM\UniqueConstraint(name: 'estate_rent_call_period', columns: ['lease_id', 'period'])]
class RentCall
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Lease::class, inversedBy: 'rentCalls')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Lease $lease;

    /** The month, as its first day. */
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $period;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dueOn;

    /** "AVIS-2026-10-B-2024-003": what a transfer's label should carry. */
    #[ORM\Column(length: 64, unique: true)]
    private string $reference = '';

    #[ORM\Column]
    private int $rent = 0;

    #[ORM\Column]
    private int $charges = 0;

    #[ORM\Column]
    private int $paid = 0;

    #[ORM\Column(length: 16, enumType: RentStatus::class)]
    private RentStatus $status = RentStatus::CALLED;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $calledAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $remindedAt = null;

    /** The latest online payment started for it (the provider's session), until it is paid or given up: estate:payment:check reads it again. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $onlineReference = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $onlineStartedAt = null;

    /** "Q-2026-0042", given when it is paid in full. */
    #[ORM\Column(length: 32, nullable: true, unique: true)]
    private ?string $receiptNumber = null;

    /** @var Collection<int, Payment> */
    #[ORM\OneToMany(targetEntity: Payment::class, mappedBy: 'rentCall', cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['paidOn' => 'ASC'])]
    private Collection $payments;

    public function __construct(Lease $lease, \DateTimeImmutable $period)
    {
        $this->lease = $lease;
        $this->period = $period->modify('first day of this month')->setTime(0, 0);
        $this->dueOn = $this->period->setDate((int) $this->period->format('Y'), (int) $this->period->format('m'), $lease->getDueDay());
        $this->reference = sprintf('AVIS-%s-%s', $this->period->format('Y-m'), $lease->getReference());
        $this->rent = $lease->getRent();
        $this->charges = $lease->getCharges();
        $this->calledAt = new \DateTimeImmutable();
        $this->payments = new ArrayCollection();
    }

    public function __toString(): string { return $this->reference; }
    public function getId(): ?int { return $this->id; }
    public function getLease(): Lease { return $this->lease; }
    public function getPeriod(): \DateTimeImmutable { return $this->period; }
    public function getDueOn(): \DateTimeImmutable { return $this->dueOn; }
    public function setDueOn(\DateTimeImmutable $dueOn): self { $this->dueOn = $dueOn; return $this; }
    public function getReference(): string { return $this->reference; }
    public function getRent(): int { return $this->rent; }
    public function setRent(int $rent): self { $this->rent = $rent; return $this; }
    public function getCharges(): int { return $this->charges; }
    public function setCharges(int $charges): self { $this->charges = $charges; return $this; }
    public function getTotal(): int { return $this->rent + $this->charges; }
    public function getPaid(): int { return $this->paid; }
    public function getRemaining(): int { return max(0, $this->getTotal() - $this->paid); }
    public function getStatus(): RentStatus { return $this->status; }
    public function setStatus(RentStatus $status): self { $this->status = $status; return $this; }
    public function getCalledAt(): \DateTimeImmutable { return $this->calledAt; }
    public function setCalledAt(\DateTimeImmutable $calledAt): self { $this->calledAt = $calledAt; return $this; }
    public function getPaidAt(): ?\DateTimeImmutable { return $this->paidAt; }
    public function getRemindedAt(): ?\DateTimeImmutable { return $this->remindedAt; }
    public function setRemindedAt(?\DateTimeImmutable $remindedAt): self { $this->remindedAt = $remindedAt; return $this; }
    public function getOnlineReference(): ?string { return $this->onlineReference; }
    public function getOnlineStartedAt(): ?\DateTimeImmutable { return $this->onlineStartedAt; }
    public function setOnline(?string $reference, ?\DateTimeImmutable $startedAt = null): self { $this->onlineReference = $reference; $this->onlineStartedAt = null === $reference ? null : ($startedAt ?? new \DateTimeImmutable()); return $this; }
    public function getReceiptNumber(): ?string { return $this->receiptNumber; }
    public function setReceiptNumber(?string $receiptNumber): self { $this->receiptNumber = $receiptNumber; return $this; }
    /** @return Collection<int, Payment> */
    public function getPayments(): Collection { return $this->payments; }

    /** Settles it by a payment: partial, then paid once the total is reached. */
    public function addPayment(Payment $payment): self
    {
        if (!$this->payments->contains($payment)) {
            $this->payments->add($payment);
            $this->paid += $payment->getAmount();
            if ($this->paid >= $this->getTotal()) {
                $this->status = RentStatus::PAID;
                $this->paidAt = \DateTimeImmutable::createFromInterface($payment->getPaidOn());
            } else {
                $this->status = RentStatus::PARTIAL;
            }
        }

        return $this;
    }

    /** Takes a payment back (a bank line unreconciled): called again, or partial, as what is left says. */
    public function removePayment(Payment $payment): self
    {
        if ($this->payments->removeElement($payment)) {
            $this->paid -= $payment->getAmount();
            if ($this->paid < $this->getTotal()) {
                $this->status = $this->paid > 0 ? RentStatus::PARTIAL : RentStatus::CALLED;
                $this->paidAt = null;
            }
        }

        return $this;
    }

    public function isPaid(): bool { return RentStatus::PAID === $this->status; }

    public function isLate(?\DateTimeImmutable $today = null): bool
    {
        return !$this->isPaid() && $this->dueOn < ($today ?? new \DateTimeImmutable('today'));
    }
}
