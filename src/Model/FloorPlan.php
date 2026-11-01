<?php

namespace Base\Estate\Model;

use Base\Estate\Entity\Room;
use Base\Estate\Entity\Unit;

/**
 * A home's plan as the pages draw it: each level's rooms with their outline
 * (SVG points, centimetres), where their name goes and whether it fits, the
 * level's frame, and the totals. Read-only: what a tenant, an owner or the
 * public sees (the editor has its own JSON).
 */
final class FloorPlan
{
    /** The margin around a level's rooms, in centimetres. */
    private const MARGIN = 40;

    /**
     * @param list<array{level: int, viewBox: string, width: int, height: int, font: float, rooms: list<array<string, mixed>>}> $levels
     */
    private function __construct(
        public readonly Unit $unit,
        public readonly array $levels,
        public readonly ?float $livingArea,
        public readonly float $totalArea,
    ) {
    }

    public static function of(Unit $unit): self
    {
        // The home's own level first, then upstairs, then the basement.
        $byLevel = $unit->getRoomsByLevel();
        uksort($byLevel, fn (int $a, int $b) => [$a < 0, abs($a)] <=> [$b < 0, abs($b)]);

        $frames = [];
        foreach ($byLevel as $level => $rooms) {
            $bounds = [\PHP_INT_MAX, \PHP_INT_MAX, \PHP_INT_MIN, \PHP_INT_MIN];
            foreach ($rooms as $room) {
                [$a, $b, $c, $d] = $room->getShape()->bounds();
                $bounds = [min($bounds[0], $a), min($bounds[1], $b), max($bounds[2], $c), max($bounds[3], $d)];
            }
            $frames[$level] = $bounds;
        }
        $widest = max([1, ...array_map(fn (array $b) => $b[2] - $b[0] + 2 * self::MARGIN, $frames)]);
        $tallest = max([1, ...array_map(fn (array $b) => $b[3] - $b[1] + 2 * self::MARGIN, $frames)]);
        // One scale and one lettering for every level: a cellar is drawn as small as it is.
        // A name readable on a phone (12 px at 390): a 30th of the plan's longer side, between 20 and 42 cm.
        $font = max(20.0, min(42.0, max($widest, $tallest) / 30));

        $levels = [];
        $number = 0;
        foreach ($byLevel as $level => $rooms) {
            $bounds = $frames[$level];
            $width = $bounds[2] - $bounds[0] + 2 * self::MARGIN;
            $height = $bounds[3] - $bounds[1] + 2 * self::MARGIN;
            $levels[] = [
                'level' => $level,
                'viewBox' => sprintf('%d %d %d %d', $bounds[0] - self::MARGIN, $bounds[1] - self::MARGIN, $width, $height),
                'width' => $width,
                'height' => $height,
                // Its share of the widest level's width, in per cent: the drawing's width on the page.
                'scale' => round(100 * $width / $widest, 2),
                'font' => round($font, 1),
                'rooms' => array_map(function (Room $room) use ($font, &$number) { return self::room($room, $font) + ['number' => ++$number]; }, $rooms),
                'area' => round(array_sum(array_map(fn (Room $r) => $r->getSurface(), $rooms)), 2),
            ];
        }

        return new self($unit, $levels, $unit->getLivingArea(), $unit->getPlanSurface());
    }

    public function isEmpty(): bool
    {
        return [] === $this->levels;
    }

    /** @return array<string, mixed> */
    private static function room(Room $room, float $font): array
    {
        $shape = $room->getShape();
        [$x, $y, $free] = $shape->label();
        $name = $room->getName();
        // Room for the name and its surface on two lines, or only the surface, or nothing (a cupboard).
        $nameWidth = mb_strlen($name) * $font * 0.58;
        $surfaceWidth = 7 * $font * 0.58;
        [$minX, $minY, $maxX, $maxY] = $shape->bounds();

        $height = $maxY - $minY;
        $fits = static fn (float $width): bool => $width <= $free * 0.92;

        return [
            'room' => $room,
            'points' => $shape->svg(),
            'x' => round($x, 1),
            'y' => round($y, 1),
            // Its name over its surface; else its number over its surface (the table names it), or both on one line; else its number alone.
            'showName' => $fits(max($nameWidth, $surfaceWidth)) && $height >= 2.6 * $font,
            'showSurface' => $fits($surfaceWidth) && $height >= 2.6 * $font,
            // A strip (a hallway): its number and surface on one line.
            'showInline' => $fits($surfaceWidth + 3 * $font * 0.58) && $height >= 1.3 * $font,
        ];
    }
}
