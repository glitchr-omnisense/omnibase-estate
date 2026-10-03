<?php

namespace Base\Estate\Entity;

use Base\Entity\User;
use Doctrine\ORM\Mapping as ORM;

/** A line of a work order's timeline: a step, or a note from whoever follows it. */
#[ORM\Entity]
#[ORM\Table(name: 'estate_work_order_event')]
class WorkOrderEvent
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WorkOrder::class, inversedBy: 'events')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?WorkOrder $workOrder = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    /** Who wrote it, as shown: "Gestion", "Plomberie Kieffer", "Léa Muller". */
    #[ORM\Column(length: 160)]
    private string $authorName = '';

    #[ORM\Column(type: 'text')]
    private string $body = '';

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $authorName, string $body, ?User $author = null)
    {
        $this->authorName = $authorName;
        $this->body = $body;
        $this->author = $author;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getWorkOrder(): ?WorkOrder { return $this->workOrder; }
    public function setWorkOrder(?WorkOrder $workOrder): self { $this->workOrder = $workOrder; return $this; }
    public function getAuthor(): ?User { return $this->author; }
    public function getAuthorName(): string { return $this->authorName; }
    public function getBody(): string { return $this->body; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): self { $this->createdAt = $createdAt; return $this; }
}
