<?php

namespace Base\Estate\Entity;

use Base\Database\Attribute\Uploader;
use Base\Estate\Enum\PhotoPhase;
use Doctrine\ORM\Mapping as ORM;

/**
 * A photo of a story's entry: before or after the works (or neither), the
 * view it shows ("La cuisine, vers la fenêtre") which pairs a before with an
 * after, a caption. Private until the team ticks "OK to publish": a photo in
 * a home lived in often shows its tenant's belongings or people.
 */
#[ORM\Entity]
#[ORM\Table(name: 'estate_story_photo')]
class StoryPhoto
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: StoryEntry::class, inversedBy: 'photos')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?StoryEntry $entry = null;

    /** Untyped: the form hands the setter an UploadedFile; the column keeps its uuid. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Uploader(max_size: '20MB', mime_types: ['image/*'], missable: true)]
    private $image = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $caption = null;

    #[ORM\Column(length: 8, nullable: true, enumType: PhotoPhase::class)]
    private ?PhotoPhase $phase = null;

    /** What it shows: a before and an after of the same view make a pair. */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $view = null;

    /** Cleared by the team for the public story and the networks; private by default. */
    #[ORM\Column]
    private bool $publishable = false;

    /** The work order's document it was taken from, if any (its id: the document may go). */
    #[ORM\Column(nullable: true)]
    private ?int $documentId = null;

    #[ORM\Column]
    private int $position = 0;

    public function __toString(): string { return $this->caption ?? $this->view ?? ('#'.$this->id); }
    public function getId(): ?int { return $this->id; }
    public function getEntry(): ?StoryEntry { return $this->entry; }
    public function setEntry(?StoryEntry $entry): self { $this->entry = $entry; return $this; }
    public function getImage(): ?string { return Uploader::getPublic($this, 'image'); }
    public function getImageFile() { return Uploader::get($this, 'image'); }
    public function setImage($image): self { $this->image = $image ?: null; $this->entry?->touch(); return $this; }
    public function hasImage(): bool { return null !== $this->image; }
    public function getCaption(): ?string { return $this->caption; }
    public function setCaption(?string $caption): self { $this->caption = $caption ? trim($caption) : null; $this->entry?->touch(); return $this; }
    public function getPhase(): ?PhotoPhase { return $this->phase; }
    public function setPhase(?PhotoPhase $phase): self { $this->phase = $phase; $this->entry?->touch(); return $this; }
    public function getView(): ?string { return $this->view; }
    public function setView(?string $view): self { $this->view = $view ? trim($view) : null; $this->entry?->touch(); return $this; }
    public function isPublishable(): bool { return $this->publishable; }
    public function setPublishable(bool $publishable): self { $this->publishable = $publishable; $this->entry?->touch(); return $this; }
    public function getDocumentId(): ?int { return $this->documentId; }
    public function setDocumentId(?int $documentId): self { $this->documentId = $documentId; return $this; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(?int $position): self { $this->position = (int) $position; return $this; }

    /** What the public reads under it, or as its alternative text. */
    public function getLabel(): string
    {
        return $this->caption ?? $this->view ?? '';
    }
}
