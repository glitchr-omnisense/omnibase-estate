<?php

namespace Base\Estate\Admin\Widget\Dashboard;

use Base\Estate\Repository\RentCallRepository;
use Base\Estate\Repository\WorkOrderRepository;
use Base\Estate\Service\PortfolioFigures;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The summary: one band across the dashboard with the figures read first - the homes let, the
 * month's rents received over called, what is late, the monthly rent roll, the works in progress
 * and the year's cost -, each opening its list. Every organisation together, each figure one
 * query for them all (PortfolioFigures and the repositories' totals). Placed full width:
 *
 *   MenuItem::block('estate_summary', 'estate_widget.summary', 'fa-solid fa-gauge')->setSize(4)
 */
final class SummaryWidgetType extends AbstractEstateWidgetType
{
    public function __construct(
        EntityManagerInterface $entityManager,
        private readonly PortfolioFigures $figures,
        private readonly RentCallRepository $calls,
        private readonly WorkOrderRepository $workOrders,
    ) {
        parent::__construct($entityManager);
    }

    public static function getName(): string { return 'estate_summary'; }
    public function getDefaultLabel(): string { return 'estate_widget.summary'; }
    public function getDefaultIcon(): ?string { return 'fa-solid fa-gauge'; }
    protected function template(): string { return 'summary'; }

    protected function data(array $organisations, array $params): array
    {
        $today = new \DateTimeImmutable('today');
        $homes = $this->figures->homes($organisations, $today);
        $let = array_filter($homes, static fn (array $h) => 'vacant' !== $h['state']);
        $late = $this->calls->overdueTotals($organisations, $today);
        $sum = [
            'let' => \count($let),
            'lettable' => \count($homes),
            'monthly' => array_sum(array_column($let, 'monthly')),
            'lateTotal' => $late['total'],
            'late' => $late['count'],
            'works' => $this->workOrders->openTotals($organisations)['count'],
            'worksSpent' => $this->workOrders->spentBy($organisations, $today->setDate((int) $today->format('Y'), 1, 1), $today->setTime(23, 59, 59)),
        ];
        [$previous, $month] = $this->figures->months($organisations, 2, $today);
        // The current month once its rents are called, the last one before that - as the late card.
        $first = $today->modify('first day of this month');
        [$period, $month] = $month['called'] > 0 ? [$first, $month] : [$first->modify('-1 month'), $previous];

        return $sum + [
            'share' => $sum['lettable'] > 0 ? (int) round(100 * $sum['let'] / $sum['lettable']) : 0,
            'month' => $period,
            'called' => $month['called'],
            'received' => $month['received'],
            'rate' => $month['called'] > 0 ? (int) round(100 * $month['received'] / $month['called']) : 0,
            'year' => (int) $today->format('Y'),
        ];
    }
}
