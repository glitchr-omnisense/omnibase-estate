<?php

namespace Base\Estate\Entity;

use Base\Estate\Enum\LeaseStatus;
use Base\Estate\Enum\PartyRole;
use Base\Estate\Enum\PaymentMethod;
use Base\Estate\Repository\LeaseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A lease of a unit: who lives there (LeaseParty), from when, for how much,
 * on which day the rent is due. Its rent calls are made each month from it.
 */
#[ORM\Entity(repositoryClass: LeaseRepository::class)]
#[ORM\Table(name: 'estate_lease')]
class Lease
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Unit::class, inversedBy: 'leases')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Unit $unit;

    /** "B-2024-003": printed on notices and receipts, and expected in transfer labels. */
    #[ORM\Column(length: 32, unique: true)]
    private string $reference = '';

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $startOn;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $endOn = null;

    #[ORM\Column]
    private int $rent = 0;

    #[ORM\Column]
    private int $charges = 0;

    #[ORM\Column]
    private int $deposit = 0;

    /** The day of the month the rent is due. */
    #[ORM\Column(type: 'smallint')]
    private int $dueDay = 5;

    #[ORM\Column(length: 16, enumType: PaymentMethod::class)]
    private PaymentMethod $paymentMethod = PaymentMethod::TRANSFER;

    #[ORM\Column(length: 16, enumType: LeaseStatus::class)]
    private LeaseStatus $status = LeaseStatus::ACTIVE;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $noticeOn = null;

    /** When the tenant's home insurance certificate runs out. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $insuranceUntil = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    /**
     * The rent's reference index (IRL, INSEE's indice de référence des loyers): its quarter
     * ("T2 2025") and value, those of the signature or of the last revision - the yearly
     * revision divides the new quarter's value by this one (RentRevisions).
     */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $indexQuarter = null;

    #[ORM\Column(type: 'decimal', precision: 7, scale: 2, nullable: true)]
    private ?string $indexValue = null;

    /** When the rent was last revised. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $revisedOn = null;

    /** @var Collection<int, LeaseParty> */
    #[ORM\OneToMany(targetEntity: LeaseParty::class, mappedBy: 'lease', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $parties;

    /** @var Collection<int, RentCall> */
    #[ORM\OneToMany(targetEntity: RentCall::class, mappedBy: 'lease', cascade: ['remove'])]
    #[ORM\OrderBy(['period' => 'DESC'])]
    private Collection $rentCalls;

    public function __construct(Unit $unit, string $reference = '', ?\DateTimeImmutable $startOn = null)
    {
        $this->unit = $unit;
        $this->reference = $reference;
        $this->startOn = $startOn ?? new \DateTimeImmutable('today');
        $this->rent = $unit->getRent();
        $this->charges = $unit->getCharges();
        $this->deposit = $unit->getDeposit();
        $this->parties = new ArrayCollection();
        $this->rentCalls = new ArrayCollection();
    }

    public function __toString(): string { return $this->reference.' · '.$this->unit->getName(); }
    public function getId(): ?int { return $this->id; }
    public function getUnit(): Unit { return $this->unit; }
    public function setUnit(Unit $unit): self { $this->unit = $unit; return $this; }
    public function getOrganisation(): Organisation { return $this->unit->getOrganisation(); }
    public function getReference(): string { return $this->reference; }
    public function setReference(string $reference): self { $this->reference = $reference; return $this; }
    public function getStartOn(): \DateTimeImmutable { return $this->startOn; }
    public function setStartOn(\DateTimeImmutable $startOn): self { $this->startOn = $startOn; return $this; }
    public function getEndOn(): ?\DateTimeImmutable { return $this->endOn; }
    public function setEndOn(?\DateTimeImmutable $endOn): self { $this->endOn = $endOn; return $this; }
    public function getRent(): int { return $this->rent; }
    public function setRent(?int $rent): self { $this->rent = (int) $rent; return $this; }
    public function getCharges(): int { return $this->charges; }
    public function setCharges(?int $charges): self { $this->charges = (int) $charges; return $this; }
    public function getMonthly(): int { return $this->rent + $this->charges; }
    public function getDeposit(): int { return $this->deposit; }
    public function setDeposit(?int $deposit): self { $this->deposit = (int) $deposit; return $this; }
    public function getDueDay(): int { return $this->dueDay; }
    public function setDueDay(?int $dueDay): self { $this->dueDay = max(1, min(28, (int) $dueDay)); return $this; }
    public function getPaymentMethod(): PaymentMethod { return $this->paymentMethod; }
    public function setPaymentMethod(PaymentMethod $paymentMethod): self { $this->paymentMethod = $paymentMethod; return $this; }
    public function getStatus(): LeaseStatus { return $this->status; }
    public function setStatus(LeaseStatus $status): self { $this->status = $status; return $this; }
    public function getNoticeOn(): ?\DateTimeImmutable { return $this->noticeOn; }
    public function setNoticeOn(?\DateTimeImmutable $noticeOn): self { $this->noticeOn = $noticeOn; return $this; }
    public function getIndexQuarter(): ?string { return $this->indexQuarter; }
    public function setIndexQuarter(?string $indexQuarter): self { $this->indexQuarter = $indexQuarter ? strtoupper(trim($indexQuarter)) : null; return $this; }
    public function getIndexValue(): ?string { return $this->indexValue; }
    public function setIndexValue(string|float|null $indexValue): self { $this->indexValue = null === $indexValue || '' === $indexValue ? null : number_format((float) str_replace(',', '.', (string) $indexValue), 2, '.', ''); return $this; }
    public function getRevisedOn(): ?\DateTimeImmutable { return $this->revisedOn; }
    public function setRevisedOn(?\DateTimeImmutable $revisedOn): self { $this->revisedOn = $revisedOn; return $this; }
    public function getInsuranceUntil(): ?\DateTimeImmutable { return $this->insuranceUntil; }
    public function setInsuranceUntil(?\DateTimeImmutable $insuranceUntil): self { $this->insuranceUntil = $insuranceUntil; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self { $this->notes = $notes; return $this; }

    /** @return Collection<int, LeaseParty> */
    public function getParties(): Collection { return $this->parties; }
    public function addParty(LeaseParty $party): self
    {
        if (!$this->parties->contains($party)) {
            $this->parties->add($party);
            $party->setLease($this);
        }

        return $this;
    }
    public function removeParty(LeaseParty $party): self { $this->parties->removeElement($party); return $this; }

    /** @return list<LeaseParty> the tenants and co-tenants, not the guarantors */
    public function getTenants(): array
    {
        return array_values($this->parties->filter(fn (LeaseParty $p) => PartyRole::GUARANTOR !== $p->getRole())->toArray());
    }

    public function getTenantNames(): string
    {
        return implode(', ', array_map(fn (LeaseParty $p) => $p->getName(), $this->getTenants()));
    }

    /** @return Collection<int, RentCall> */
    public function getRentCalls(): Collection { return $this->rentCalls; }

    public function isRunningOn(\DateTimeImmutable $on): bool
    {
        return LeaseStatus::ENDED !== $this->status && $this->startOn <= $on && (null === $this->endOn || $this->endOn >= $on);
    }
}
