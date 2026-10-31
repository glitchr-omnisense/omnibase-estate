<?php

namespace Base\Estate\Entity;

use Base\Database\Attribute\Uploader;
use Base\Estate\Enum\SplitMode;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A supplier's invoice the co-owners share - the roof repaired for
 * 6 000 € -: its supplier (one of the contractors, or a name), date,
 * label, amount all taxes included and VAT code, the expense account, the
 * file, which home it is for. Divided by the shares in force on its date,
 * or by hand (SplitMode), then posted (Service\Ownerships::post()): the
 * expense, the supplier's debt and each co-owner's part as a call for funds.
 * Its parts are kept as posted (holder => minor units), so the statements
 * read what was called.
 */
#[ORM\Entity]
#[ORM\Table(name: 'estate_ownership_invoice')]
class OwnershipInvoice
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Ownership::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Ownership $ownership;

    /** The home it is for; null: the whole ownership. */
    #[ORM\ManyToOne(targetEntity: Unit::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Unit $unit = null;

    #[ORM\ManyToOne(targetEntity: Contractor::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Contractor $contractor = null;

    /** The supplier's name when it is not one of the contractors (the tax office, an insurer). */
    #[ORM\Column(length: 160, nullable: true)]
    private ?string $supplierName = null;

    /** The supplier's invoice number. */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $number = null;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: 160)]
    private string $label = '';

    /** All taxes included, in minor units: what the invoice says. */
    #[ORM\Column]
    private int $amount = 0;

    /** A VAT code of the book (A20...); posted as VAT only when the book is liable. */
    #[ORM\Column(length: 12, nullable: true)]
    private ?string $taxCode = null;

    #[ORM\Column(length: 12)]
    private string $expenseAccount = '615';

    #[ORM\Column(length: 255, nullable: true)]
    #[Uploader(max_size: '20MB', mime_types: ['application/pdf', 'image/*'], missable: true)]
    private $file = null;

    #[ORM\Column(length: 16, enumType: SplitMode::class)]
    private SplitMode $splitMode = SplitMode::SHARES;

    /** By hand: holder => minor units (AMOUNTS) or basis points (PERCENTAGES). */
    #[ORM\Column(type: 'json')]
    private array $split = [];

    /** As posted: holder => minor units of the shared cost. */
    #[ORM\Column(type: 'json')]
    private array $parts = [];

    /** The cost the parts divide: the amount, less the VAT the book gets back. */
    #[ORM\Column(nullable: true)]
    private ?int $sharedAmount = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $postedAt = null;

    /** @var Collection<int, OwnershipCall> */
    #[ORM\OneToMany(targetEntity: OwnershipCall::class, mappedBy: 'invoice')]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $calls;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(?Ownership $ownership = null, string $label = '', int $amount = 0, ?\DateTimeImmutable $date = null)
    {
        if ($ownership) {
            $this->ownership = $ownership;
        }
        $this->label = $label;
        $this->amount = $amount;
        $this->date = $date ?? new \DateTimeImmutable('today');
        $this->calls = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string { return trim($this->label.' — '.$this->getSupplier()); }
    public function getId(): ?int { return $this->id; }
    public function getOwnership(): Ownership { return $this->ownership; }
    public function setOwnership(Ownership $ownership): self { $this->ownership = $ownership; return $this; }
    public function getUnit(): ?Unit { return $this->unit; }
    public function setUnit(?Unit $unit): self { $this->unit = $unit; return $this; }
    public function getContractor(): ?Contractor { return $this->contractor; }
    public function setContractor(?Contractor $contractor): self { $this->contractor = $contractor; return $this; }
    public function getSupplierName(): ?string { return $this->supplierName; }
    public function setSupplierName(?string $supplierName): self { $this->supplierName = '' !== trim((string) $supplierName) ? trim((string) $supplierName) : null; return $this; }
    public function getSupplier(): string { return $this->contractor?->getCompany() ?? $this->supplierName ?? ''; }
    public function getNumber(): ?string { return $this->number; }
    public function setNumber(?string $number): self { $this->number = '' !== trim((string) $number) ? trim((string) $number) : null; return $this; }
    public function getDate(): \DateTimeImmutable { return $this->date; }
    public function setDate(?\DateTimeImmutable $date): self { $this->date = $date ?? new \DateTimeImmutable('today'); return $this; }
    public function getLabel(): string { return $this->label; }
    public function setLabel(?string $label): self { $this->label = trim((string) $label); return $this; }
    public function getAmount(): int { return $this->amount; }
    public function setAmount(?int $amount): self { $this->amount = max(0, (int) $amount); return $this; }
    public function getTaxCode(): ?string { return $this->taxCode; }
    public function setTaxCode(?string $taxCode): self { $taxCode = strtoupper(trim((string) $taxCode)); $this->taxCode = '' === $taxCode ? null : $taxCode; return $this; }
    public function getExpenseAccount(): string { return $this->expenseAccount; }
    public function setExpenseAccount(?string $expenseAccount): self { $this->expenseAccount = preg_replace('/\D/', '', (string) $expenseAccount) ?: '615'; return $this; }
    public function getFile(): ?string { return Uploader::getPublic($this, 'file'); }
    public function setFile($file): self { $this->file = $file ?: null; return $this; }
    public function hasFile(): bool { return null !== $this->file; }
    public function getSplitMode(): SplitMode { return $this->splitMode; }
    public function setSplitMode(SplitMode $splitMode): self { $this->splitMode = $splitMode; return $this; }
    /** @return array<string, int> */
    public function getSplit(): array { return $this->split; }
    /** @param array<string, int> $split */
    public function setSplit(array $split): self { $this->split = array_map('intval', $split); return $this; }
    /** @return array<string, int> */
    public function getParts(): array { return $this->parts; }
    public function getPart(string $holder): int { return $this->parts[$holder] ?? 0; }
    public function getSharedAmount(): ?int { return $this->sharedAmount; }
    public function getPostedAt(): ?\DateTimeImmutable { return $this->postedAt; }
    public function isPosted(): bool { return null !== $this->postedAt; }
    /** @return Collection<int, OwnershipCall> */
    public function getCalls(): Collection { return $this->calls; }
    public function addCall(OwnershipCall $call): self { if (!$this->calls->contains($call)) { $this->calls->add($call); } return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** @param array<string, int> $parts holder => minor units of $sharedAmount */
    public function markPosted(array $parts, int $sharedAmount, ?\DateTimeImmutable $at = null): self
    {
        $this->parts = $parts;
        $this->sharedAmount = $sharedAmount;
        $this->postedAt = $at ?? new \DateTimeImmutable();

        return $this;
    }
}
