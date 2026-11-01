<?php

namespace Tests\Base\Estate\Model;

use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\Room;
use Base\Estate\Entity\Unit;
use Base\Estate\Enum\RoomKind;
use Base\Estate\Model\FloorPlan;
use Base\Estate\Model\Polygon;
use PHPUnit\Framework\TestCase;

/**
 * A home's plan: each room's surface from its shape, a measured figure prevailing, the living area
 * from the rooms counted (not a balcony), the gap with the surface declared.
 */
final class FloorPlanTest extends TestCase
{
    private function unit(float $declared = 30.0): Unit
    {
        return (new Unit(new Organisation('SCI Test', 'sci-test'), 'Appartement', 'appartement'))->setSurface($declared);
    }

    private function room(Unit $unit, string $name, RoomKind $kind, array $points, ?float $measured = null): Room
    {
        $room = (new Room($unit, $name, $kind))->setPolygon($points)->setMeasuredSurface($measured);
        $unit->addPlanRoom($room);

        return $room;
    }

    public function testASurfaceIsComputedFromTheShapeOnTheGrid(): void
    {
        $unit = $this->unit();
        // 4.20 m by 3.10 m, its corners typed a few millimetres off the grid.
        $room = $this->room($unit, 'Chambre', RoomKind::BEDROOM, [[0, 0], [421, 2], [419, 309], [1, 311]]);

        self::assertSame([[0, 0], [420, 0], [420, 310], [0, 310]], $room->getPolygon(), 'snapped to 10 cm');
        self::assertSame(13.02, $room->getDrawnSurface());
        self::assertSame(13.02, $room->getSurface());
        self::assertFalse($room->isMeasured());
    }

    public function testAnLShapeIsMeasuredWhole(): void
    {
        $unit = $this->unit();
        // 5 m x 4 m less a 2 m x 2 m corner.
        $room = $this->room($unit, 'Séjour', RoomKind::LIVING, [[0, 0], [500, 0], [500, 200], [300, 200], [300, 400], [0, 400]]);

        self::assertSame(16.0, $room->getSurface());
        [$x, $y] = $room->getShape()->label();
        self::assertTrue($room->getShape()->contains($x, $y), 'its name is written inside it');
    }

    public function testTheMeasuredFigurePrevails(): void
    {
        $unit = $this->unit();
        $room = $this->room($unit, 'Cuisine', RoomKind::KITCHEN, [[0, 0], [300, 0], [300, 280], [0, 280]], 8.65);

        self::assertSame(8.4, $room->getDrawnSurface());
        self::assertSame(8.65, $room->getSurface());
        self::assertTrue($room->isMeasured());
        self::assertSame(8.65, $unit->getLivingArea());
        self::assertSame(8.65, FloorPlan::of($unit)->livingArea);
    }

    public function testTheLivingAreaLeavesOutABalconyAndTheGapIsTheTeams(): void
    {
        $unit = $this->unit(30.0);
        $this->room($unit, 'Séjour', RoomKind::LIVING, [[0, 0], [500, 0], [500, 400], [0, 400]]);
        $this->room($unit, 'Chambre', RoomKind::BEDROOM, [[500, 0], [800, 0], [800, 300], [500, 300]]);
        $balcony = $this->room($unit, 'Balcon', RoomKind::BALCONY, [[0, -150], [500, -150], [500, 0], [0, 0]]);

        self::assertFalse($balcony->isCounted(), 'a balcony is not living area');
        self::assertSame(29.0, $unit->getLivingArea());
        self::assertSame(36.5, $unit->getPlanSurface());
        self::assertSame(-1.0, $unit->getSurfaceGap(), 'a square metre less than declared');

        $unit->setSurface(29.2);
        self::assertNull($unit->getSurfaceGap(), 'under half a square metre: they agree');

        $plan = FloorPlan::of($unit);
        self::assertCount(1, $plan->levels);
        self::assertCount(3, $plan->levels[0]['rooms']);
        self::assertSame([1, 2, 3], array_column($plan->levels[0]['rooms'], 'number'));
    }

    public function testNoRoomNoPlan(): void
    {
        $unit = $this->unit();

        self::assertFalse($unit->hasPlan());
        self::assertNull($unit->getLivingArea());
        self::assertNull($unit->getSurfaceGap());
    }

    public function testAShapeThatIsNoRoomIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Polygon::from([[0, 0], [300, 0], [300, 0]]);
    }

    public function testLevelsAreDrawnApart(): void
    {
        $unit = $this->unit();
        $this->room($unit, 'Séjour', RoomKind::LIVING, [[0, 0], [400, 0], [400, 400], [0, 400]]);
        $this->room($unit, 'Cave', RoomKind::CELLAR, [[0, 0], [300, 0], [300, 200], [0, 200]])->setLevel(-1);

        $plan = FloorPlan::of($unit);
        self::assertSame([0, -1], array_column($plan->levels, 'level'), 'the home\'s own level first');
        self::assertSame(100.0, $plan->levels[0]['scale']);
        self::assertSame(round(100 * 380 / 480, 2), $plan->levels[1]['scale'], 'the cellar drawn at the same scale, narrower');
        self::assertSame(16.0, $unit->getLivingArea(), 'a cellar is not living area');
    }
}
