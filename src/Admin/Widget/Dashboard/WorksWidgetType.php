<?php

namespace Base\Estate\Admin\Widget\Dashboard;

use Base\Estate\Repository\WorkOrderRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Works: the interventions in progress, their status and quote, and what the year's works cost. A
 * compact card: the first four orders listed, the counts and sums of them all read in the database.
 */
final class WorksWidgetType extends AbstractEstateWidgetType
{
    protected const COMPACT = true;

    public function __construct(EntityManagerInterface $entityManager, private readonly WorkOrderRepository $workOrders)
    {
        parent::__construct($entityManager);
    }

    public static function getName(): string { return 'estate_works'; }
    public function getDefaultLabel(): string { return 'estate_widget.works'; }
    public function getDefaultIcon(): ?string { return 'fa-solid fa-screwdriver-wrench'; }
    protected function template(): string { return 'works'; }

    protected function data(array $organisations, array $params): array
    {
        $today = new \DateTimeImmutable('today');
        $open = $this->workOrders->openTotals($organisations);

        return [
            'open' => $this->workOrders->findOpenFirst($organisations, 4),
            'openCount' => $open['count'],
            'spent' => $this->workOrders->spentBy($organisations, $today->setDate((int) $today->format('Y'), 1, 1), $today->setTime(23, 59, 59)),
            'year' => $today->format('Y'),
            'quoted' => $open['quoted'],
        ];
    }
}
