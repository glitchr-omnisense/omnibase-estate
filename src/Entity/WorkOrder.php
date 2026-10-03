<?php

namespace Base\Estate\Entity;

use Base\Entity\User;
use Base\Estate\Enum\Urgency;
use Base\Estate\Enum\WorkOrderStatus;
use Base\Estate\Repository\WorkOrderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Something to fix in a unit: reported by its tenant or the management,
 * given to a contractor, visited, quoted, done, closed. Each step is a line
 * of its timeline (WorkOrderEvent), and each tells the people concerned.
 */
#[ORM\Entity(repositoryClass: WorkOrderRepository::class)]
#[ORM\Table(name: 'estate_work_order')]
class WorkOrder
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Unit::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Unit $unit;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $reportedBy = null;

    #[ORM\Column(length: 160)]
    private string $title = '';

    #[ORM\Column(type: 'text')]
    private string $description = '';

    /** "Plomberie", "Chauffage", "Électricité", "Serrurerie", "Autre". */
    #[ORM\Column(length: 80)]
    private string $category = 'Autre';

    #[ORM\Column(length: 16, enumType: Urgency::class)]
    private Urgency $urgency = Urgency::NORMAL;

    #[ORM\Column(length: 16, enumType: WorkOrderStatus::class)]
    private WorkOrderStatus $status = WorkOrderStatus::REPORTED;

    #[ORM\ManyToOne(targetEntity: Contractor::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Contractor $contractor = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $visitAt = null;

    /** In cents. */
    #[ORM\Column(nullable: true)]
    private ?int $quoteAmount = null;

    #[ORM\Column(nullable: true)]
    private ?int $invoiceAmount = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $invoiceNumber = null;

    /** What the contractor did, in their words. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $report = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    /** @var Collection<int, WorkOrderEvent> */
    #[ORM\OneToMany(targetEntity: WorkOrderEvent::class, mappedBy: 'workOrder', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $events;

    public function __construct(Unit $unit, string $title = '', string $description = '')
    {
        $this->unit = $unit;
        $this->title = $title;
        $this->description = $description;
        $this->createdAt = new \DateTimeImmutable();
        $this->events = new ArrayCollection();
    }

    public function __toString(): string { return sprintf('#%d %s', $this->id, $this->title); }
    public function getId(): ?int { return $this->id; }
    public function getUnit(): Unit { return $this->unit; }
    public function setUnit(Unit $unit): self { $this->unit = $unit; return $this; }
    public function getOrganisation(): Organisation { return $this->unit->getOrganisation(); }
    public function getReportedBy(): ?User { return $this->reportedBy; }
    public function setReportedBy(?User $reportedBy): self { $this->reportedBy = $reportedBy; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = $title; return $this; }
    public function getDescription(): string { return $this->description; }
    public function setDescription(string $description): self { $this->description = $description; return $this; }
    public function getCategory(): string { return $this->category; }
    public function setCategory(string $category): self { $this->category = $category; return $this; }
    public function getUrgency(): Urgency { return $this->urgency; }
    public function setUrgency(Urgency $urgency): self { $this->urgency = $urgency; return $this; }
    public function getStatus(): WorkOrderStatus { return $this->status; }
    public function setStatus(WorkOrderStatus $status): self { $this->status = $status; return $this; }
    public function getContractor(): ?Contractor { return $this->contractor; }
    public function setContractor(?Contractor $contractor): self { $this->contractor = $contractor; return $this; }
    public function getVisitAt(): ?\DateTimeImmutable { return $this->visitAt; }
    public function setVisitAt(?\DateTimeImmutable $visitAt): self { $this->visitAt = $visitAt; return $this; }
    public function getQuoteAmount(): ?int { return $this->quoteAmount; }
    public function setQuoteAmount(?int $quoteAmount): self { $this->quoteAmount = $quoteAmount; return $this; }
    public function getInvoiceAmount(): ?int { return $this->invoiceAmount; }
    public function setInvoiceAmount(?int $invoiceAmount): self { $this->invoiceAmount = $invoiceAmount; return $this; }
    public function getInvoiceNumber(): ?string { return $this->invoiceNumber; }
    public function setInvoiceNumber(?string $invoiceNumber): self { $this->invoiceNumber = $invoiceNumber; return $this; }
    public function getReport(): ?string { return $this->report; }
    public function setReport(?string $report): self { $this->report = $report; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): self { $this->createdAt = $createdAt; return $this; }
    public function getClosedAt(): ?\DateTimeImmutable { return $this->closedAt; }
    public function setClosedAt(?\DateTimeImmutable $closedAt): self { $this->closedAt = $closedAt; return $this; }
    /** @return Collection<int, WorkOrderEvent> */
    public function getEvents(): Collection { return $this->events; }
    public function addEvent(WorkOrderEvent $event): self
    {
        if (!$this->events->contains($event)) {
            $this->events->add($event);
            $event->setWorkOrder($this);
        }

        return $this;
    }
}
