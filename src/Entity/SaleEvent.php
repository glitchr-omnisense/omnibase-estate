<?php

namespace Base\Estate\Entity;

use Base\Database\Attribute\Uploader;
use Base\Estate\Enum\SaleStep;
use Doctrine\ORM\Mapping as ORM;

/**
 * A line of a sale's timeline: a step reached on its date, a few words,
 * and its document (the signed offer, the compromis, the loan's
 * agreement, the acte...). The seller and the buyer see it in their
 * spaces (EstateVoter::SALE_VIEW).
 */
#[ORM\Entity]
#[ORM\Table(name: 'estate_sale_event')]
class SaleEvent
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Sale::class, inversedBy: 'events')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Sale $sale = null;

    #[ORM\Column(length: 16, enumType: SaleStep::class)]
    private SaleStep $step;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    /** Untyped: the form hands the setter an UploadedFile; the column keeps its uuid. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Uploader(max_size: '30MB', mime_types: ['application/pdf', 'image/*'], missable: true)]
    private $file = null;

    /** The document's name as shown: "Compromis signé.pdf". */
    #[ORM\Column(length: 160, nullable: true)]
    private ?string $fileName = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(SaleStep $step, \DateTimeImmutable $date, ?string $note = null)
    {
        $this->step = $step;
        $this->date = $date->setTime(0, 0);
        $this->note = null !== $note && '' !== trim($note) ? trim($note) : null;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getSale(): ?Sale { return $this->sale; }
    public function setSale(?Sale $sale): self { $this->sale = $sale; return $this; }
    public function getStep(): SaleStep { return $this->step; }
    public function getDate(): \DateTimeImmutable { return $this->date; }
    public function getNote(): ?string { return $this->note; }
    public function getFile(): ?string { return Uploader::getPublic($this, 'file'); }
    public function setFile($file): self { $this->file = $file ?: null; return $this; }
    public function hasFile(): bool { return null !== $this->file; }
    public function getFileName(): ?string { return $this->fileName; }
    public function setFileName(?string $fileName): self { $this->fileName = '' !== trim((string) $fileName) ? mb_substr(trim((string) $fileName), 0, 160) : null; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
