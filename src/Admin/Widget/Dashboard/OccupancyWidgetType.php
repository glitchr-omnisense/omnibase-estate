<?php

namespace Base\Estate\Admin\Widget\Dashboard;

use Base\Estate\Entity\Unit;
use Base\Estate\Service\Figures;
use Doctrine\ORM\EntityManagerInterface;

/** Occupancy: a donut of the homes let and vacant, the vacant ones named. A compact card. */
final class OccupancyWidgetType extends AbstractEstateWidgetType
{
    protected const COMPACT = true;

    public function __construct(EntityManagerInterface $entityManager, private readonly Figures $figures)
    {
        parent::__construct($entityManager);
    }

    public static function getName(): string { return 'estate_occupancy'; }
    public function getDefaultLabel(): string { return 'estate_widget.occupancy'; }
    public function getDefaultIcon(): ?string { return 'fa-solid fa-house-circle-check'; }
    protected function template(): string { return 'occupancy'; }

    protected function data(array $organisations, array $params): array
    {
        $let = 0;
        $lettable = 0;
        $vacant = [];
        foreach ($organisations as $organisation) {
            $f = $this->figures->of($organisation);
            $let += $f['let'];
            $lettable += $f['lettable'];
            $vacant = [...$vacant, ...$f['vacant']];
        }
        $share = $lettable > 0 ? $let / $lettable * 100 : 0.0;

        return [
            'let' => $let,
            'lettable' => $lettable,
            'vacant' => array_map(static fn (Unit $u) => $u->getName(), $vacant),
            'share' => (int) round($share),
            // A circle of circumference 100: the let arc, then the vacant one, a gap between.
            'letDash' => $lettable > 0 && $let < $lettable ? max(0, round($share - 1.5, 2)) : round($share, 2),
            'vacantDash' => $lettable > 0 && $let > 0 ? max(0, round(100 - $share - 1.5, 2)) : round(100 - $share, 2),
            'vacantOffset' => round(25 - $share, 2),
        ];
    }
}
