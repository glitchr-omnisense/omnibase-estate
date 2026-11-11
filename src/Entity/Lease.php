<?php

namespace Base\Estate\Entity;

use Base\Estate\Enum\LeaseKind;
use Base\Estate\Enum\LeaseStatus;
use Base\Estate\Enum\PartyRole;
use Base\Estate\Enum\PaymentMethod;
use Base\Estate\Enum\RevisionRule;
use Base\Estate\Repository\LeaseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A lease of a unit: who lives there (LeaseParty), from when, for how much,
 * on which day the rent is due. Its rent calls are made each month from it.
 * A home's lease (the default) or a commercial or professional one (kind):
 * its index (IRL, ILC, ILAT) and its revision rule follow (RevisionRule).
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

    /** A home's lease, or a commercial or professional one. */
    #[ORM\Column(length: 16, enumType: LeaseKind::class, options: ['default' => 'residential'])]
    private LeaseKind $kind = LeaseKind::RESIDENTIAL;

    /** The index the rent follows, as INSEE names it: IRL for a home, ILC or ILAT, chosen on the lease, otherwise. */
    #[ORM\Column(length: 4, options: ['default' => 'IRL'])]
    private string $indexName = 'IRL';

    #[ORM\Column(length: 16, enumType: RevisionRule::class, options: ['default' => 'yearly'])]
    private RevisionRule $revisionRule = RevisionRule::YEARLY;

    /**
     * The tenant is a small or medium-sized business (the definition of annex I to regulation
     * (EU) n° 651/2014): the ILC's yearly change was capped for it (loi n° 2022-1158, art. 14,
     * Model\IndexCaps). Declared by the team; estate does not check it.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $smallBusiness = false;

    /**
     * A flatshare's common folder in the site's cloud (omnibase/drive: Colocations/<home>,
     * Drive\FlatshareFolders), kept once made: a later lease of the same home gets its own.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $folder = null;

    /**
     * The flatshare's group of occupants (omnibase/drive's ShareGroup), by its id - no foreign
     * key, estate does not require omnibase/drive. Renamed by the team, it is still this one.
     */
    #[ORM\Column(nullable: true)]
    private ?int $groupId = null;

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
    public function getKind(): LeaseKind { return $this->kind; }
    public function setKind(LeaseKind $kind): self { $this->kind = $kind; return $this; }
    public function isResidential(): bool { return LeaseKind::RESIDENTIAL === $this->kind; }
    public function getIndexName(): string { return $this->indexName; }
    public function setIndexName(?string $indexName): self { $this->indexName = strtoupper(trim((string) $indexName)) ?: 'IRL'; return $this; }
    public function getRevisionRule(): RevisionRule { return $this->revisionRule; }
    public function setRevisionRule(RevisionRule $revisionRule): self { $this->revisionRule = $revisionRule; return $this; }
    public function isSmallBusiness(): bool { return $this->smallBusiness; }
    public function setSmallBusiness(?bool $smallBusiness): self { $this->smallBusiness = (bool) $smallBusiness; return $this; }

    /** The index and the revision rule its kind allows: a home on the IRL, a commercial or professional lease on the ILC or the ILAT. */
    #[Assert\Callback]
    public function validateTerms(ExecutionContextInterface $context): void
    {
        if (!\in_array($this->indexName, $this->kind->indices(), true)) {
            $context->buildViolation(sprintf('Un %s suit %s.', mb_strtolower($this->kind->label()), implode(' ou ', $this->kind->indices())))->atPath('indexName')->addViolation();
        }
        if (!\in_array($this->revisionRule, $this->kind->rules(), true)) {
            $context->buildViolation(sprintf('« %s » ne vaut pas pour un %s.', $this->revisionRule->label(), mb_strtolower($this->kind->label())))->atPath('revisionRule')->addViolation();
        }
    }

    public function getInsuranceUntil(): ?\DateTimeImmutable { return $this->insuranceUntil; }
    public function setInsuranceUntil(?\DateTimeImmutable $insuranceUntil): self { $this->insuranceUntil = $insuranceUntil; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self { $this->notes = $notes; return $this; }
    public function getFolder(): ?string { return $this->folder; }
    public function setFolder(?string $folder): self { $this->folder = $folder; return $this; }
    public function getGroupId(): ?int { return $this->groupId; }
    public function setGroupId(?int $groupId): self { $this->groupId = $groupId; return $this; }

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

    /** Two occupants or more - a flatshare, or a couple: they share a folder (Drive\FlatshareFolders). */
    public function isShared(): bool
    {
        return \count($this->getTenants()) >= 2;
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
