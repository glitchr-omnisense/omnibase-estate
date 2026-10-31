<?php

namespace Base\Estate\Entity;

use Base\Estate\Enum\PayoutStatus;
use Doctrine\ORM\Mapping as ORM;

/**
 * What the agency transfers to an owner at a period's end: their balance
 * that day less the mandate's reserve. Issued (taken off their account,
 * RV-2026-0001 - the reference the transfer carries, so the bank line
 * finds it), put in a SEPA file (pain.001) for the bank, then paid - the
 * bank line reconciled against it, or marked by hand.
 */
#[ORM\Entity(repositoryClass: \Base\Estate\Repository\MandatePayoutRepository::class)]
#[ORM\Table(name: 'estate_mandate_payout')]
class MandatePayout
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Mandate::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Mandate $mandate;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $reference = null;

    /** The day the balance was taken: the period's last day. */
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $balanceOn;

    /** The owner's balance that day, before this payout. */
    #[ORM\Column]
    private int $balance = 0;

    /** What the mandate kept on their account. */
    #[ORM\Column]
    private int $reserve = 0;

    #[ORM\Column]
    private int $amount = 0;

    /** Where it goes: the owner's IBAN when it was issued. */
    #[ORM\Column(length: 34, nullable: true)]
    private ?string $iban = null;

    #[ORM\Column(length: 16, enumType: PayoutStatus::class)]
    private PayoutStatus $status = PayoutStatus::ISSUED;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $issuedOn;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $exportedAt = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $paidOn = null;

    /** The bank line or the reference it was paid under. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $paidReference = null;

    public function __construct(Mandate $mandate, \DateTimeImmutable $balanceOn, int $balance, int $reserve, int $amount, ?\DateTimeImmutable $issuedOn = null)
    {
        $this->mandate = $mandate;
        $this->balanceOn = $balanceOn->setTime(0, 0);
        $this->balance = $balance;
        $this->reserve = $reserve;
        $this->amount = $amount;
        $this->iban = $mandate->getMandator()->getIban();
        $this->issuedOn = ($issuedOn ?? new \DateTimeImmutable('today'))->setTime(0, 0);
    }

    public function __toString(): string { return (string) $this->reference; }
    public function getId(): ?int { return $this->id; }
    public function getMandate(): Mandate { return $this->mandate; }
    public function getMandator(): Mandator { return $this->mandate->getMandator(); }
    public function getReference(): ?string { return $this->reference; }
    public function setReference(string $reference): self { $this->reference = $reference; return $this; }
    public function getBalanceOn(): \DateTimeImmutable { return $this->balanceOn; }
    public function getBalance(): int { return $this->balance; }
    public function getReserve(): int { return $this->reserve; }
    public function getAmount(): int { return $this->amount; }
    public function getIban(): ?string { return $this->iban; }
    public function setIban(?string $iban): self { $iban = strtoupper(preg_replace('/\s+/', '', (string) $iban)); $this->iban = '' === $iban ? null : $iban; return $this; }
    public function getStatus(): PayoutStatus { return $this->status; }
    public function getIssuedOn(): \DateTimeImmutable { return $this->issuedOn; }
    public function getExportedAt(): ?\DateTimeImmutable { return $this->exportedAt; }
    public function getPaidOn(): ?\DateTimeImmutable { return $this->paidOn; }
    public function getPaidReference(): ?string { return $this->paidReference; }
    public function isPaid(): bool { return PayoutStatus::PAID === $this->status; }

    public function markExported(?\DateTimeImmutable $at = null): self
    {
        if (!$this->isPaid()) {
            $this->status = PayoutStatus::EXPORTED;
        }
        $this->exportedAt = $at ?? new \DateTimeImmutable();

        return $this;
    }

    public function markPaid(\DateTimeImmutable $on, ?string $reference = null): self
    {
        $this->status = PayoutStatus::PAID;
        $this->paidOn = $on->setTime(0, 0);
        $this->paidReference = null !== $reference ? mb_substr($reference, 0, 120) : null;

        return $this;
    }
}
