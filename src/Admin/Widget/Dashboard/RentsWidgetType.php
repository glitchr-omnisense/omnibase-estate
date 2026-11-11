<?php

namespace Base\Estate\Admin\Widget\Dashboard;

use Base\Estate\Service\PortfolioFigures;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Rents called and received, month by month: for each month the rent called
 * as a light bar and what came in drawn inside it, with the legend, an
 * axis, the period switched in the card (6 or 12 months, no script), a
 * tooltip per month and a table for screen readers; the year's rate above. One query for every
 * organisation and both periods (PortfolioFigures::months()).
 */
final class RentsWidgetType extends AbstractEstateWidgetType
{
    public const PERIODS = [6, 12];

    public function __construct(EntityManagerInterface $entityManager, private readonly PortfolioFigures $figures)
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
        $today = new \DateTimeImmutable('today');
        // No organisation, no month: the card says there is nothing yet.
        $all = [] === $organisations ? [] : $this->figures->months($organisations, max(self::PERIODS), $today);
        foreach (self::PERIODS as $count) {
            $months = array_map(static fn (array $m) => ['month' => $m['month'], 'called' => $m['called'], 'received' => $m['received']], \array_slice($all, -$count));
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
