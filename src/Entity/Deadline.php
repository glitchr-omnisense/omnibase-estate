<?php

namespace Base\Estate\Entity;

use Doctrine\ORM\Mapping as ORM;

/** A date the company must not miss: the property tax, the general meeting, an insurance renewal. */
#[ORM\Entity]
#[ORM\Table(name: 'estate_deadline')]
class Deadline
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Organisation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Organisation $organisation;

    #[ORM\Column(length: 160)]
    private string $title = '';

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dueOn;

    /** Comes back each year: once past, its next one is shown. */
    #[ORM\Column]
    private bool $yearly = true;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    public function __construct(Organisation $organisation, string $title = '', ?\DateTimeImmutable $dueOn = null)
    {
        $this->organisation = $organisation;
        $this->title = $title;
        $this->dueOn = $dueOn ?? new \DateTimeImmutable('today');
    }

    public function __toString(): string { return $this->title; }
    public function getId(): ?int { return $this->id; }
    public function getOrganisation(): Organisation { return $this->organisation; }
    public function setOrganisation(Organisation $organisation): self { $this->organisation = $organisation; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = $title; return $this; }
    public function getDueOn(): \DateTimeImmutable { return $this->dueOn; }
    public function setDueOn(\DateTimeImmutable $dueOn): self { $this->dueOn = $dueOn; return $this; }
    public function isYearly(): bool { return $this->yearly; }
    public function setYearly(bool $yearly): self { $this->yearly = $yearly; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self { $this->notes = $notes; return $this; }

    /** Its next date from today: itself, or - yearly and past - the same day of a later year. */
    public function next(?\DateTimeImmutable $today = null): \DateTimeImmutable
    {
        $today ??= new \DateTimeImmutable('today');
        $date = $this->dueOn;
        while ($this->yearly && $date < $today) {
            $date = $date->modify('+1 year');
        }

        return $date;
    }
}
