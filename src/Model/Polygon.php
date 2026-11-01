<?php

namespace Base\Estate\Model;

/**
 * A room's shape on the plan: its corners in centimetres, in order, on a
 * grid (10 cm by default). Pure geometry: the area by the shoelace formula,
 * the bounds, a point inside to write the room's name on.
 */
final class Polygon
{
    /** The editor's grid, in centimetres. */
    public const GRID = 10;

    /** No room is wider than this (1 km): a typo, not a home. */
    private const LIMIT = 100000;

    /** @param list<array{0: int, 1: int}> $points */
    private function __construct(private readonly array $points)
    {
    }

    /**
     * From what the editor sends: a list of [x, y] in centimetres, snapped
     * to the grid, the repeated corners dropped.
     *
     * @param mixed $points
     *
     * @throws \InvalidArgumentException when it is not a polygon
     */
    public static function from(mixed $points, int $grid = self::GRID): self
    {
        if (!\is_array($points)) {
            throw new \InvalidArgumentException('A room needs its corners.');
        }
        $clean = [];
        foreach (array_values($points) as $point) {
            if (!\is_array($point) || !isset($point[0], $point[1]) || !is_numeric($point[0]) || !is_numeric($point[1])) {
                throw new \InvalidArgumentException('A corner is two numbers, in centimetres.');
            }
            $x = (int) (round((float) $point[0] / $grid) * $grid);
            $y = (int) (round((float) $point[1] / $grid) * $grid);
            if (abs($x) > self::LIMIT || abs($y) > self::LIMIT) {
                throw new \InvalidArgumentException('A corner is out of the plan.');
            }
            if ($clean && end($clean) === [$x, $y]) {
                continue;
            }
            $clean[] = [$x, $y];
        }
        if (\count($clean) > 1 && $clean[0] === end($clean)) {
            array_pop($clean);
        }
        if (\count($clean) < 3) {
            throw new \InvalidArgumentException('A room has three corners at least.');
        }
        $polygon = new self($clean);
        if ($polygon->area() <= 0) {
            throw new \InvalidArgumentException('A room has a surface.');
        }

        return $polygon;
    }

    /** A rectangle, from its corner and its size in centimetres. */
    public static function rectangle(int $x, int $y, int $width, int $height): self
    {
        return self::from([[$x, $y], [$x + $width, $y], [$x + $width, $y + $height], [$x, $y + $height]], 1);
    }

    /** @return list<array{0: int, 1: int}> */
    public function points(): array
    {
        return $this->points;
    }

    /** In square centimetres. */
    public function area(): float
    {
        $sum = 0;
        $n = \count($this->points);
        for ($i = 0; $i < $n; ++$i) {
            [$x1, $y1] = $this->points[$i];
            [$x2, $y2] = $this->points[($i + 1) % $n];
            $sum += $x1 * $y2 - $x2 * $y1;
        }

        return abs($sum) / 2;
    }

    /** In square metres, two decimals. */
    public function squareMetres(): float
    {
        return round($this->area() / 10000, 2);
    }

    /** @return array{0: int, 1: int, 2: int, 3: int} min x, min y, max x, max y */
    public function bounds(): array
    {
        $xs = array_column($this->points, 0);
        $ys = array_column($this->points, 1);

        return [min($xs), min($ys), max($xs), max($ys)];
    }

    public function contains(float $x, float $y): bool
    {
        $inside = false;
        $n = \count($this->points);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$xi, $yi] = $this->points[$i];
            [$xj, $yj] = $this->points[$j];
            if (($yi > $y) !== ($yj > $y) && $x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    /**
     * Where its name goes: the centroid when it falls inside (a rectangle,
     * most rooms), else the middle of the widest stretch across the room
     * at the centroid's height or at its bounds' middle (an L).
     *
     * @return array{0: float, 1: float, 2: float} x, y, and the width free there
     */
    public function label(): array
    {
        $area = 0.0;
        $cx = 0.0;
        $cy = 0.0;
        $n = \count($this->points);
        for ($i = 0; $i < $n; ++$i) {
            [$x1, $y1] = $this->points[$i];
            [$x2, $y2] = $this->points[($i + 1) % $n];
            $cross = $x1 * $y2 - $x2 * $y1;
            $area += $cross;
            $cx += ($x1 + $x2) * $cross;
            $cy += ($y1 + $y2) * $cross;
        }
        [$minX, $minY, $maxX, $maxY] = $this->bounds();
        $cx = $area ? $cx / (3 * $area) : ($minX + $maxX) / 2;
        $cy = $area ? $cy / (3 * $area) : ($minY + $maxY) / 2;

        $best = null;
        foreach ([$cy, ($minY + $maxY) / 2, $minY + ($maxY - $minY) / 4, $minY + 3 * ($maxY - $minY) / 4] as $y) {
            foreach ($this->spans($y) as [$from, $to]) {
                if ($from <= $cx && $cx <= $to) {
                    return [$cx, $cy, $to - $from];
                }
                if (null === $best || $to - $from > $best[2]) {
                    $best = [($from + $to) / 2, $y, $to - $from];
                }
            }
        }

        return $best ?? [$cx, $cy, $maxX - $minX];
    }

    /** @return list<array{0: float, 1: float}> the stretches inside the room along a horizontal line */
    private function spans(float $y): array
    {
        $xs = [];
        $n = \count($this->points);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$xi, $yi] = $this->points[$i];
            [$xj, $yj] = $this->points[$j];
            if (($yi > $y) !== ($yj > $y)) {
                $xs[] = $xi + ($y - $yi) * ($xj - $xi) / ($yj - $yi);
            }
        }
        sort($xs);
        $spans = [];
        for ($i = 0; $i + 1 < \count($xs); $i += 2) {
            $spans[] = [$xs[$i], $xs[$i + 1]];
        }

        return $spans;
    }

    /** As SVG wants it: "x,y x,y ...". */
    public function svg(): string
    {
        return implode(' ', array_map(fn (array $p) => $p[0].','.$p[1], $this->points));
    }
}
