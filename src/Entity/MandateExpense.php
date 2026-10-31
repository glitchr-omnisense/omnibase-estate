<?php

namespace Base\Estate\Entity;

use Base\Database\Attribute\Uploader;
use Doctrine\ORM\Mapping as ORM;

/**
 * An expense the agency charges to an owner's account: a work order's
 * invoice on one of their homes (made when the work order is closed), or
 * one entered by hand - the building's charges called by its syndic, an
 * insurance, the property tax paid for them. Its amount all taxes
 * included: the owner bears the VAT. Posted once (Ledger\ManagementBooks:
 * 4671 the owner / 401 the supplier).
 */
#[ORM\Entity]
#[ORM\Table(name: 'estate_mandate_expense')]
class MandateExpense
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Mandate::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Mandate $mandate;

    #[ORM\ManyToOne(targetEntity: Unit::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Unit $unit = null;

    /** The work order it comes from, if any: charged once. */
    #[ORM\ManyToOne(targetEntity: WorkOrder::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?WorkOrder $workOrder = null;

    #[ORM\ManyToOne(targetEntity: Contractor::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Contractor $contractor = null;

    /** The supplier's name when it is not one of the contractors (a syndic, an insurer, the tax office). */
    #[ORM\Column(length: 160, nullable: true)]
    private ?string $supplierName = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $number = null;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: 160)]
    private string $label = '';

    /** All taxes included, in minor units. */
    #[ORM\Column]
    private int $amount = 0;

    #[ORM\Column(length: 255, nullable: true)]
    #[Uploader(max_size: '20MB', mime_types: ['application/pdf', 'image/*'], missable: true)]
    private $file = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $postedAt = null;

    public function __construct(?Mandate $mandate = null, string $label = '', int $amount = 0, ?\DateTimeImmutable $date = null)
    {
        if ($mandate) {
            $this->mandate = $mandate;
        }
        $this->label = $label;
        $this->amount = $amount;
        $this->date = ($date ?? new \DateTimeImmutable('today'))->setTime(0, 0);
    }

    public function __toString(): string { return $this->label; }
    public function getId(): ?int { return $this->id; }
    public function getMandate(): Mandate { return $this->mandate; }
    public function setMandate(Mandate $mandate): self { $this->mandate = $mandate; return $this; }
    public function getUnit(): ?Unit { return $this->unit; }
    public function setUnit(?Unit $unit): self { $this->unit = $unit; return $this; }
    public function getWorkOrder(): ?WorkOrder { return $this->workOrder; }
    public function setWorkOrder(?WorkOrder $workOrder): self { $this->workOrder = $workOrder; return $this; }
    public function getContractor(): ?Contractor { return $this->contractor; }
    public function setContractor(?Contractor $contractor): self { $this->contractor = $contractor; return $this; }
    public function getSupplierName(): ?string { return $this->supplierName; }
    public function setSupplierName(?string $name): self { $this->supplierName = '' !== trim((string) $name) ? trim((string) $name) : null; return $this; }
    /** Who it is owed to: the contractor's company, or the name typed. */
    public function getSupplier(): string { return $this->contractor?->getCompany() ?? $this->supplierName ?? ''; }
    public function getNumber(): ?string { return $this->number; }
    public function setNumber(?string $number): self { $this->number = '' !== trim((string) $number) ? trim((string) $number) : null; return $this; }
    public function getDate(): \DateTimeImmutable { return $this->date; }
    public function setDate(?\DateTimeImmutable $date): self { $this->date = ($date ?? new \DateTimeImmutable('today'))->setTime(0, 0); return $this; }
    public function getLabel(): string { return $this->label; }
    public function setLabel(?string $label): self { $this->label = trim((string) $label); return $this; }
    public function getAmount(): int { return $this->amount; }
    public function setAmount(?int $amount): self { $this->amount = max(0, (int) $amount); return $this; }
    public function getFile(): ?string { return Uploader::getPublic($this, 'file'); }
    public function setFile($file): self { $this->file = $file ?: null; return $this; }
    public function hasFile(): bool { return null !== $this->file; }
    public function getPostedAt(): ?\DateTimeImmutable { return $this->postedAt; }
    public function isPosted(): bool { return null !== $this->postedAt; }
    public function markPosted(?\DateTimeImmutable $at = null): self { $this->postedAt = $at ?? new \DateTimeImmutable(); return $this; }
}
