<?php

namespace Base\Estate\Admin\Widget\Dashboard;

use Base\Estate\Service\Figures;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Rents called and received, month by month: for each month the rent called
 * as a light bar and what came in drawn inside it, with the legend, an
 * axis, the period switched in the card (6 or 12 months, no script), a
 * tooltip per month and a table for screen readers; the year's rate above.
 */
final class RentsWidgetType extends AbstractEstateWidgetType
{
    public const PERIODS = [6, 12];

    public function __construct(EntityManagerInterface $entityManager, private readonly Figures $figures)
    {
        parent::__construct($entityManager);
    }

    public static function getName(): string { return 'estate_rents'; }
    public function getDefaultLabel(): string { return 'estate_widget.rents'; }
    public function getDefaultIcon(): ?string { return 'fa-solid fa-chart-column'; }
    protected function template(): string { return 'rents'; }

    protected function data(array $organisations, array $params): array
    {
        $period = \in_array((int) ($params['months'] ?? 0), self::PERIODS, true) ? (int) $params['months'] : 6;
        $series = [];
        foreach (self::PERIODS as $count) {
            $months = [];
            foreach ($organisations as $organisation) {
                foreach ($this->figures->months($organisation, $count) as $i => $month) {
                    $months[$i] ??= ['month' => $month['month'], 'called' => 0, 'received' => 0];
                    $months[$i]['called'] += $month['called'];
                    $months[$i]['received'] += $month['received'];
                }
            }
            $scale = self::scale(max([0, ...array_map(static fn ($m) => max($m['called'], $m['received']), $months)]));
            foreach ($months as $i => $month) {
                $months[$i]['calledHeight'] = round(min(100, $month['called'] / $scale['max'] * 100), 2);
                $months[$i]['receivedHeight'] = round(min(100, $month['received'] / $scale['max'] * 100), 2);
                $months[$i]['rate'] = $month['called'] > 0 ? (int) round($month['received'] / $month['called'] * 100) : null;
            }
            $called = array_sum(array_column($months, 'called'));
            $received = array_sum(array_column($months, 'received'));
            $series[$count] = ['months' => array_values($months), 'scale' => $scale, 'called' => $called, 'received' => $received, 'rate' => $called > 0 ? (int) round($received / $called * 100) : null];
        }

        return ['series' => $series, 'period' => $period];
    }
}
