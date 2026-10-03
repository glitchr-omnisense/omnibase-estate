<?php

namespace Base\Estate\Entity;

use Base\Estate\Enum\ActivityKind;
use Base\Estate\Repository\ActivityRepository;
use Doctrine\ORM\Mapping as ORM;

/** A line of the company's day-to-day, as its associates read it: a rent paid, a boiler replaced. */
#[ORM\Entity(repositoryClass: ActivityRepository::class)]
#[ORM\Table(name: 'estate_activity')]
#[ORM\Index(name: 'estate_activity_at', columns: ['organisation_id', 'happenedAt'])]
class Activity
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Organisation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Organisation $organisation;

    #[ORM\ManyToOne(targetEntity: Unit::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Unit $unit = null;

    #[ORM\Column(length: 24, enumType: ActivityKind::class)]
    private ActivityKind $kind;

    #[ORM\Column(length: 255)]
    private string $message;

    /** In cents, signed: money in, money out. */
    #[ORM\Column(nullable: true)]
    private ?int $amount = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $happenedAt;

    public function __construct(Organisation $organisation, ActivityKind $kind, string $message, ?Unit $unit = null, ?int $amount = null, ?\DateTimeImmutable $happenedAt = null)
    {
        $this->organisation = $organisation;
        $this->kind = $kind;
        $this->message = $message;
        $this->unit = $unit;
        $this->amount = $amount;
        $this->happenedAt = $happenedAt ?? new \DateTimeImmutable();
    }

    public function __toString(): string { return $this->message; }
    public function getId(): ?int { return $this->id; }
    public function getOrganisation(): Organisation { return $this->organisation; }
    public function getUnit(): ?Unit { return $this->unit; }
    public function getKind(): ActivityKind { return $this->kind; }
    public function getMessage(): string { return $this->message; }
    public function getAmount(): ?int { return $this->amount; }
    public function getHappenedAt(): \DateTimeImmutable { return $this->happenedAt; }
}
