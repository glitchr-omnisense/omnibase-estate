<?php

namespace Base\Estate\Admin\Widget\Dashboard;

use Base\Estate\Service\Figures;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The summary: one band across the dashboard with the figures read first - the homes let, the
 * month's rents received over called, what is late, the monthly rent roll, the works in progress
 * and the year's cost -, each opening its list. Every organisation together. Placed full width:
 *
 *   MenuItem::block('estate_summary', 'estate_widget.summary', 'fa-solid fa-gauge')->setSize(4)
 */
final class SummaryWidgetType extends AbstractEstateWidgetType
{
    public function __construct(EntityManagerInterface $entityManager, private readonly Figures $figures)
    {
        parent::__construct($entityManager);
    }

    public static function getName(): string { return 'estate_summary'; }
    public function getDefaultLabel(): string { return 'estate_widget.summary'; }
    public function getDefaultIcon(): ?string { return 'fa-solid fa-gauge'; }
    protected function template(): string { return 'summary'; }

    protected function data(array $organisations, array $params): array
    {
        $today = new \DateTimeImmutable('today');
        $sum = ['let' => 0, 'lettable' => 0, 'monthly' => 0, 'lateTotal' => 0, 'late' => 0, 'works' => 0, 'worksSpent' => 0];
        $month = ['called' => 0, 'received' => 0];
        $previous = ['called' => 0, 'received' => 0];
        foreach ($organisations as $organisation) {
            $f = $this->figures->of($organisation, $today);
            $sum['let'] += $f['let'];
            $sum['lettable'] += $f['lettable'];
            $sum['monthly'] += $f['monthly'];
            $sum['lateTotal'] += $f['lateTotal'];
            $sum['late'] += \count($f['late']);
            $sum['works'] += \count($f['openWorkOrders']);
            $sum['worksSpent'] += $f['worksSpent'];
            [$last, $current] = $this->figures->months($organisation, 2, $today);
            $month['called'] += $current['called'];
            $month['received'] += $current['received'];
            $previous['called'] += $last['called'];
            $previous['received'] += $last['received'];
        }
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
