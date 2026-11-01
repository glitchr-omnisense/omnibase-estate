<?php

namespace Base\Estate\Entity;

use Base\Estate\Enum\PhotoPhase;
use Base\Estate\Enum\StoryState;
use Base\Estate\Repository\StoryEntryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A line of a home's story, its public timeline: built in, bought, a new
 * kitchen, a facade redone. A date, a title, a text and photos; works under
 * way (in progress: started on, planned to end on, progress photos) or done;
 * started by the team, or from a work order, linked to the work orders it
 * tells (their state suggests its own, the team decides). Its investment's
 * amount shows only when the team ticks it. Nothing of it is public until
 * the team publishes it, and of its photos only those it cleared one by one
 * (StoryPhoto::$publishable).
 */
#[ORM\Entity(repositoryClass: StoryEntryRepository::class)]
#[ORM\Table(name: 'estate_story_entry')]
#[ORM\Index(columns: ['published', 'happenedOn'], name: 'estate_story_entry_published_idx')]
class StoryEntry
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Unit::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Unit $unit;

    /** @var Collection<int, WorkOrder> the works it tells */
    #[ORM\ManyToMany(targetEntity: WorkOrder::class)]
    #[ORM\JoinTable(name: 'estate_story_entry_work_order')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    private Collection $workOrders;

    #[ORM\Column(length: 16, enumType: StoryState::class)]
    private StoryState $state = StoryState::DONE;

    /** Works under way: when they started, when they should end. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedOn = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $plannedEndOn = null;

    /** What the company invests, in cents; public only when $showAmount. */
    #[ORM\Column(nullable: true)]
    private ?int $amount = null;

    #[ORM\Column]
    private bool $showAmount = false;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $happenedOn;

    #[ORM\Column(length: 160)]
    private string $title = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $text = null;

    #[ORM\Column]
    private bool $published = false;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, StoryPhoto> */
    #[ORM\OneToMany(targetEntity: StoryPhoto::class, mappedBy: 'entry', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $photos;

    public function __construct(Unit $unit, string $title = '', ?\DateTimeImmutable $happenedOn = null)
    {
        $this->unit = $unit;
        $this->title = $title;
        $this->happenedOn = $happenedOn ?? new \DateTimeImmutable('today');
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->photos = new ArrayCollection();
        $this->workOrders = new ArrayCollection();
    }

    public function __toString(): string { return $this->title; }
    public function getId(): ?int { return $this->id; }
    public function getUnit(): Unit { return $this->unit; }
    public function setUnit(Unit $unit): self { $this->unit = $unit; return $this->touch(); }
    public function getOrganisation(): Organisation { return $this->unit->getOrganisation(); }
    /** @return Collection<int, WorkOrder> */
    public function getWorkOrders(): Collection { return $this->workOrders; }
    public function addWorkOrder(WorkOrder $order): self { if (!$this->workOrders->contains($order)) { $this->workOrders->add($order); } return $this; }
    public function removeWorkOrder(WorkOrder $order): self { $this->workOrders->removeElement($order); return $this; }
    /** The first works it tells (the one it was started from). */
    public function getWorkOrder(): ?WorkOrder { return $this->workOrders->first() ?: null; }
    public function getState(): StoryState { return $this->state; }
    public function setState(StoryState $state): self { $this->state = $state; return $this->touch(); }
    public function isInProgress(): bool { return StoryState::IN_PROGRESS === $this->state; }
    public function getStartedOn(): ?\DateTimeImmutable { return $this->startedOn; }
    public function setStartedOn(?\DateTimeImmutable $startedOn): self { $this->startedOn = $startedOn; return $this->touch(); }
    public function getPlannedEndOn(): ?\DateTimeImmutable { return $this->plannedEndOn; }
    public function setPlannedEndOn(?\DateTimeImmutable $plannedEndOn): self { $this->plannedEndOn = $plannedEndOn; return $this->touch(); }
    public function getAmount(): ?int { return $this->amount; }
    public function setAmount(?int $amount): self { $this->amount = null === $amount || $amount <= 0 ? null : $amount; return $this->touch(); }
    public function isShowAmount(): bool { return $this->showAmount; }
    public function setShowAmount(bool $showAmount): self { $this->showAmount = $showAmount; return $this->touch(); }

    /** The amount the public may read: only when the team ticked it. */
    public function getPublicAmount(): ?int
    {
        return $this->showAmount ? $this->amount : null;
    }

    /** @return list<StoryPhoto> the cleared photos of a phase (before, during, after) */
    public function getPublicPhotosOf(PhotoPhase $phase): array
    {
        return array_values(array_filter($this->getPublicPhotos(), fn (StoryPhoto $photo) => $phase === $photo->getPhase()));
    }
    public function getHappenedOn(): \DateTimeImmutable { return $this->happenedOn; }
    public function setHappenedOn(?\DateTimeImmutable $happenedOn): self { $this->happenedOn = $happenedOn ?? new \DateTimeImmutable('today'); return $this->touch(); }
    public function getTitle(): string { return $this->title; }
    public function setTitle(?string $title): self { $this->title = trim((string) $title); return $this->touch(); }
    public function getText(): ?string { return $this->text; }
    public function setText(?string $text): self { $this->text = null === $text || '' === trim($text) ? null : trim($text); return $this->touch(); }
    public function isPublished(): bool { return $this->published; }
    public function getPublishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }

    /** Use Stories::publish(), which checks it first. */
    public function setPublished(bool $published): self
    {
        if ($published && !$this->published) {
            $this->publishedAt = new \DateTimeImmutable();
        }
        $this->published = $published;

        return $this->touch();
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function touch(): self { $this->updatedAt = new \DateTimeImmutable(); return $this; }

    /** @return Collection<int, StoryPhoto> */
    public function getPhotos(): Collection { return $this->photos; }
    public function addPhoto(StoryPhoto $photo): self
    {
        if (!$this->photos->contains($photo)) {
            $this->photos->add($photo);
            $photo->setEntry($this);
        }

        return $this->touch();
    }
    public function removePhoto(StoryPhoto $photo): self { $this->photos->removeElement($photo); return $this->touch(); }

    /** @return list<StoryPhoto> the photos the public may see: the team cleared each one */
    public function getPublicPhotos(): array
    {
        return array_values(array_filter($this->photos->toArray(), fn (StoryPhoto $photo) => $photo->isPublishable() && $photo->hasImage()));
    }

    /**
     * The before/after pairs, by view, in the photos' order: a view's first
     * photo before and its first photo after. A view with only one of them
     * is no pair (its photo shows alone).
     *
     * @return list<array{view: string, before: StoryPhoto, after: StoryPhoto}>
     */
    public function getPairs(bool $publicOnly = false): array
    {
        $photos = $publicOnly ? $this->getPublicPhotos() : array_values(array_filter($this->photos->toArray(), fn (StoryPhoto $p) => $p->hasImage()));
        $views = [];
        foreach ($photos as $photo) {
            if (null === $photo->getPhase() || PhotoPhase::DURING === $photo->getPhase()) {
                continue;
            }
            $key = mb_strtolower(trim((string) $photo->getView()));
            $views[$key]['view'] ??= (string) $photo->getView();
            $views[$key][$photo->getPhase()->value] ??= $photo;
        }
        $pairs = [];
        foreach ($views as $view) {
            if (isset($view[PhotoPhase::BEFORE->value], $view[PhotoPhase::AFTER->value])) {
                $pairs[] = ['view' => $view['view'], 'before' => $view[PhotoPhase::BEFORE->value], 'after' => $view[PhotoPhase::AFTER->value]];
            }
        }

        return $pairs;
    }

    /** @return list<StoryPhoto> the photos outside the pairs (alone, or a view's extra ones) */
    public function getSinglePhotos(bool $publicOnly = false): array
    {
        $paired = [];
        foreach ($this->getPairs($publicOnly) as $pair) {
            $paired[] = $pair['before'];
            $paired[] = $pair['after'];
        }
        $photos = $publicOnly ? $this->getPublicPhotos() : array_values(array_filter($this->photos->toArray(), fn (StoryPhoto $p) => $p->hasImage()));

        return array_values(array_filter($photos, fn (StoryPhoto $photo) => !\in_array($photo, $paired, true)));
    }
}
