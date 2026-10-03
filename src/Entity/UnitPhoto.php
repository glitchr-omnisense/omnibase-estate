<?php

namespace Base\Estate\Entity;

use Base\Database\Attribute\Uploader;
use Doctrine\ORM\Mapping as ORM;

/** A photograph of a unit, in its gallery's order. */
#[ORM\Entity]
#[ORM\Table(name: 'estate_unit_photo')]
class UnitPhoto
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Unit::class, inversedBy: 'photos')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Unit $unit = null;

    /** Untyped: the form hands the setter an UploadedFile; the column keeps its uuid. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Uploader(max_size: '12MB', mime_types: ['image/*'], missable: true)]
    private $image = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $caption = null;

    #[ORM\Column]
    private int $position = 0;

    public function __toString(): string { return $this->caption ?? ('#'.$this->id); }
    public function getId(): ?int { return $this->id; }
    public function getUnit(): ?Unit { return $this->unit; }
    public function setUnit(?Unit $unit): self { $this->unit = $unit; return $this; }
    public function getImage(): ?string { return Uploader::getPublic($this, 'image'); }
    public function getImageFile() { return Uploader::get($this, 'image'); }
    public function setImage($image): self { $this->image = $image ?: null; return $this; }
    public function getCaption(): ?string { return $this->caption; }
    public function setCaption(?string $caption): self { $this->caption = $caption; return $this; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(?int $position): self { $this->position = (int) $position; return $this; }
}
