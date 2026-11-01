<?php

namespace Base\Estate\Entity;

use Base\Estate\Enum\RoomKind;
use Base\Estate\Model\Polygon;
use Doctrine\ORM\Mapping as ORM;

/**
 * A room of a home, drawn on its plan: its name and kind, its level, its
 * shape (corners in centimetres on a 10 cm grid), whether it counts toward
 * the living area (Carrez). Its surface is computed from the shape; a
 * figure measured on site prevails, and is shown as measured.
 */
#[ORM\Entity]
#[ORM\Table(name: 'estate_room')]
class Room
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Unit::class, inversedBy: 'planRooms')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Unit $unit;

    #[ORM\Column(length: 80)]
    private string $name = '';

    #[ORM\Column(length: 16, enumType: RoomKind::class)]
    private RoomKind $kind = RoomKind::OTHER;

    /** 0 the ground floor or the home's own level, 1 upstairs, -1 the basement. */
    #[ORM\Column]
    private int $level = 0;

    /** @var list<array{0: int, 1: int}> its corners, in centimetres */
    #[ORM\Column(type: 'json')]
    private array $polygon = [];

    /** The surface measured on site, in m²; null: the drawn one. */
    #[ORM\Column(type: 'decimal', precision: 7, scale: 2, nullable: true)]
    private ?string $measuredSurface = null;

    /** Counted in the living area (Carrez). */
    #[ORM\Column]
    private bool $counted = true;

    #[ORM\Column]
    private int $position = 0;

    public function __construct(Unit $unit, string $name = '', RoomKind $kind = RoomKind::OTHER)
    {
        $this->unit = $unit;
        $this->name = $name;
        $this->setKind($kind);
    }

    public function __toString(): string { return $this->name; }
    public function getId(): ?int { return $this->id; }
    public function getUnit(): Unit { return $this->unit; }
    public function setUnit(Unit $unit): self { $this->unit = $unit; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(?string $name): self { $this->name = trim((string) $name); return $this; }
    public function getKind(): RoomKind { return $this->kind; }

    /** A new kind brings its default count (a balcony out of the living area), which the team may change after. */
    public function setKind(RoomKind $kind): self
    {
        if ($kind !== ($this->kind ?? null)) {
            $this->counted = $kind->countsByDefault();
        }
        $this->kind = $kind;

        return $this;
    }

    public function getLevel(): int { return $this->level; }
    public function setLevel(?int $level): self { $this->level = (int) $level; return $this; }

    /** @return list<array{0: int, 1: int}> */
    public function getPolygon(): array { return $this->polygon; }

    /** @param mixed $points corners in centimetres, snapped to the grid */
    public function setPolygon(mixed $points): self
    {
        $this->polygon = Polygon::from($points)->points();

        return $this;
    }

    public function getShape(): Polygon { return Polygon::from($this->polygon, 1); }
    public function getMeasuredSurface(): ?float { return null === $this->measuredSurface ? null : (float) $this->measuredSurface; }
    public function setMeasuredSurface(float|string|null $surface): self
    {
        $surface = \is_string($surface) ? str_replace([' ', ','], ['', '.'], $surface) : $surface;
        $this->measuredSurface = null === $surface || '' === $surface || (float) $surface <= 0 ? null : number_format((float) $surface, 2, '.', '');

        return $this;
    }
    public function isCounted(): bool { return $this->counted; }
    public function setCounted(bool $counted): self { $this->counted = $counted; return $this; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(?int $position): self { $this->position = (int) $position; return $this; }

    /** The drawn surface, in m². */
    public function getDrawnSurface(): float
    {
        return [] === $this->polygon ? 0.0 : $this->getShape()->squareMetres();
    }

    public function isMeasured(): bool
    {
        return null !== $this->measuredSurface;
    }

    /** What it measures: the figure measured on site when there is one, else the drawn one. */
    public function getSurface(): float
    {
        return $this->getMeasuredSurface() ?? $this->getDrawnSurface();
    }

    /** As the editor and the API know it. */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'kind' => $this->kind->value,
            'level' => $this->level,
            'polygon' => $this->polygon,
            'measured' => $this->getMeasuredSurface(),
            'counted' => $this->counted,
            'drawn' => $this->getDrawnSurface(),
            'surface' => $this->getSurface(),
        ];
    }
}
