<?php

namespace Base\Estate\Entity;

use Base\Database\Attribute\Uploader;
use Base\Entity\User;
use Base\Estate\Enum\DocumentKind;
use Base\Estate\Repository\DocumentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A file of the company's records, attached to what it is about: a home (its
 * diagnostics, the building's rules), a lease (the signed lease, the inventory
 * of fixtures, the tenant's insurance certificate) or a work order (the
 * contractor's quote, invoice and photos). Who may open it follows from that
 * (EstateVoter::DOCUMENT_VIEW): the management and the associates always, the
 * tenants of the lease or of the home when it is shared with them, the
 * contractor of the work order.
 */
#[ORM\Entity(repositoryClass: DocumentRepository::class)]
#[ORM\Table(name: 'estate_document')]
class Document
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Organisation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Organisation $organisation;

    #[ORM\ManyToOne(targetEntity: Unit::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Unit $unit = null;

    #[ORM\ManyToOne(targetEntity: Lease::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Lease $lease = null;

    #[ORM\ManyToOne(targetEntity: WorkOrder::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?WorkOrder $workOrder = null;

    #[ORM\Column(length: 20, enumType: DocumentKind::class)]
    private DocumentKind $kind = DocumentKind::OTHER;

    #[ORM\Column(length: 160)]
    private string $title = '';

    /** Untyped: the form hands the setter an UploadedFile; the column keeps its uuid. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Uploader(max_size: '20MB', mime_types: ['application/pdf', 'image/*'], missable: true)]
    private $file = null;

    /** Shown in the tenants' space (a home's or a lease's document); the management's own otherwise. */
    #[ORM\Column]
    private bool $tenantVisible = true;

    /** Until when it holds: an insurance certificate's end, a diagnostic's validity. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $validUntil = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $uploadedBy = null;

    /** Who added it, as shown: "Gestion", "Léa Muller", "Plomberie Kieffer". */
    #[ORM\Column(length: 160, nullable: true)]
    private ?string $uploadedByName = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(?Organisation $organisation = null, DocumentKind $kind = DocumentKind::OTHER, string $title = '')
    {
        if ($organisation) {
            $this->organisation = $organisation;
        }
        $this->kind = $kind;
        $this->title = $title;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string { return $this->title; }
    public function getId(): ?int { return $this->id; }
    public function getOrganisation(): Organisation { return $this->organisation; }
    public function setOrganisation(Organisation $organisation): self { $this->organisation = $organisation; return $this; }
    public function getUnit(): ?Unit { return $this->unit ?? $this->lease?->getUnit() ?? $this->workOrder?->getUnit(); }
    public function setUnit(?Unit $unit): self { $this->unit = $unit; if ($unit) { $this->organisation = $unit->getOrganisation(); } return $this; }
    public function getLease(): ?Lease { return $this->lease; }
    public function setLease(?Lease $lease): self { $this->lease = $lease; if ($lease) { $this->organisation = $lease->getOrganisation(); } return $this; }
    public function getWorkOrder(): ?WorkOrder { return $this->workOrder; }
    public function setWorkOrder(?WorkOrder $workOrder): self { $this->workOrder = $workOrder; if ($workOrder) { $this->organisation = $workOrder->getOrganisation(); } return $this; }
    public function getKind(): DocumentKind { return $this->kind; }
    public function setKind(DocumentKind $kind): self { $this->kind = $kind; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(?string $title): self { $this->title = (string) $title; return $this; }
    public function getFile(): ?string { return Uploader::getPublic($this, 'file'); }
    public function getFileFile() { return Uploader::get($this, 'file'); }
    public function setFile($file): self { $this->file = $file ?: null; return $this; }
    public function isTenantVisible(): bool { return $this->tenantVisible; }
    public function setTenantVisible(bool $tenantVisible): self { $this->tenantVisible = $tenantVisible; return $this; }
    public function getValidUntil(): ?\DateTimeImmutable { return $this->validUntil; }
    public function setValidUntil(?\DateTimeImmutable $validUntil): self { $this->validUntil = $validUntil; return $this; }
    public function getUploadedBy(): ?User { return $this->uploadedBy; }
    public function getUploadedByName(): ?string { return $this->uploadedByName; }
    public function setUploadedBy(?User $user, ?string $name = null): self { $this->uploadedBy = $user; $this->uploadedByName = $name ?? ($user ? (string) $user : null); return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function isImage(): bool { return DocumentKind::PHOTO === $this->kind; }
}
