<?php

namespace Base\Estate\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The agency's fee on a rent collected for an owner: the mandate's rate of
 * what came in, before tax, and its VAT - kept as charged, so a rate
 * changed later leaves the past statements as they were. Taken from the
 * owner's account (Ledger\ManagementBooks: 4671 / 7061 + 44571).
 */
#[ORM\Entity]
#[ORM\Table(name: 'estate_mandate_fee')]
class MandateFee
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Mandate::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Mandate $mandate;

    /** The rent payment it is charged on; gone with it. */
    #[ORM\ManyToOne(targetEntity: Payment::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Payment $payment = null;

    #[ORM\ManyToOne(targetEntity: Unit::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Unit $unit = null;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: 160)]
    private string $label = '';

    /** What the rate applies to: the rent collected, in minor units. */
    #[ORM\Column]
    private int $base = 0;

    /** Basis points, as the mandate had it. */
    #[ORM\Column]
    private int $rate = 0;

    /** The fee before tax. */
    #[ORM\Column]
    private int $amount = 0;

    #[ORM\Column]
    private int $vat = 0;

    #[ORM\Column(length: 12, nullable: true)]
    private ?string $taxCode = null;

    public function __construct(Mandate $mandate, \DateTimeImmutable $date, string $label, int $base, int $rate, int $amount, int $vat = 0, ?string $taxCode = null)
    {
        $this->mandate = $mandate;
        $this->date = $date->setTime(0, 0);
        $this->label = $label;
        $this->base = $base;
        $this->rate = $rate;
        $this->amount = $amount;
        $this->vat = $vat;
        $this->taxCode = $taxCode;
    }

    public function getId(): ?int { return $this->id; }
    public function getMandate(): Mandate { return $this->mandate; }
    public function getPayment(): ?Payment { return $this->payment; }
    public function setPayment(?Payment $payment): self { $this->payment = $payment; return $this; }
    public function getUnit(): ?Unit { return $this->unit; }
    public function setUnit(?Unit $unit): self { $this->unit = $unit; return $this; }
    public function getDate(): \DateTimeImmutable { return $this->date; }
    public function getLabel(): string { return $this->label; }
    public function getBase(): int { return $this->base; }
    public function getRate(): int { return $this->rate; }
    public function getAmount(): int { return $this->amount; }
    public function getVat(): int { return $this->vat; }
    public function getTaxCode(): ?string { return $this->taxCode; }
    /** What the owner is charged: the fee and its VAT. */
    public function getTotal(): int { return $this->amount + $this->vat; }
}
