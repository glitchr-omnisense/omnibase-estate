<?php

namespace Base\Estate\Entity;

use Base\Entity\User;
use Base\Estate\Enum\Financing;
use Base\Estate\Enum\UnitKind;
use Doctrine\ORM\Mapping as ORM;

/**
 * Someone looking to buy (acquéreur): how to reach them, their account
 * once they have one - it opens the buyer's space -, what they look for
 * (a budget, a kind of home, a surface and rooms at least, an area) and
 * how they pay (cash, or a loan of an amount). The agency matches them
 * against its sale mandates (Service\Transactions::matches()).
 *
 * Its screens exist when the site runs the `transaction` activity.
 */
#[ORM\Entity]
#[ORM\Table(name: 'estate_buyer')]
class Buyer
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 160)]
    private string $name = '';

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $phone = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    /** The most they would pay, in minor units; 0: not said. */
    #[ORM\Column]
    private int $budget = 0;

    #[ORM\Column(length: 16, nullable: true, enumType: UnitKind::class)]
    private ?UnitKind $kind = null;

    /** The surface they need at least, in m². */
    #[ORM\Column(nullable: true)]
    private ?int $minSurface = null;

    #[ORM\Column(nullable: true)]
    private ?int $minRooms = null;

    /** Where: towns, postcodes or districts, separated by commas ("Strasbourg, 67000, Kehl"). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $area = null;

    #[ORM\Column(length: 12, enumType: Financing::class)]
    private Financing $financing = Financing::LOAN;

    /** The loan they ask for, in minor units. */
    #[ORM\Column(nullable: true)]
    private ?int $loanAmount = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name = '')
    {
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string { return $this->name; }
    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(?string $name): self { $this->name = trim((string) $name); return $this; }
    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): self { $email = mb_strtolower(trim((string) $email)); $this->email = '' === $email ? null : $email; return $this; }
    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $phone): self { $this->phone = '' !== trim((string) $phone) ? trim((string) $phone) : null; return $this; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): self { $this->user = $user; return $this; }
    public function getBudget(): int { return $this->budget; }
    public function setBudget(?int $budget): self { $this->budget = max(0, (int) $budget); return $this; }
    public function getKind(): ?UnitKind { return $this->kind; }
    public function setKind(?UnitKind $kind): self { $this->kind = $kind; return $this; }
    public function getMinSurface(): ?int { return $this->minSurface; }
    public function setMinSurface(?int $minSurface): self { $this->minSurface = $minSurface > 0 ? $minSurface : null; return $this; }
    public function getMinRooms(): ?int { return $this->minRooms; }
    public function setMinRooms(?int $minRooms): self { $this->minRooms = $minRooms > 0 ? $minRooms : null; return $this; }
    public function getArea(): ?string { return $this->area; }
    public function setArea(?string $area): self { $this->area = '' !== trim((string) $area) ? trim((string) $area) : null; return $this; }
    public function getFinancing(): Financing { return $this->financing; }
    public function setFinancing(Financing $financing): self { $this->financing = $financing; return $this; }
    public function getLoanAmount(): ?int { return $this->loanAmount; }
    public function setLoanAmount(?int $loanAmount): self { $this->loanAmount = $loanAmount > 0 ? $loanAmount : null; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self { $this->notes = '' !== trim((string) $notes) ? trim((string) $notes) : null; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** @return list<string> the area's places, lower-case: "strasbourg", "67000" */
    public function getPlaces(): array
    {
        return array_values(array_filter(array_map(fn (string $place) => mb_strtolower(trim($place)), preg_split('/[,;\n]+/', (string) $this->area))));
    }
}
