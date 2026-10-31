<?php

namespace Base\Estate\Admin\Widget\Dashboard;

use Base\Estate\Repository\WorkOrderRepository;
use Doctrine\ORM\EntityManagerInterface;

/** Works: the interventions in progress, their status and quote, and what the year's works cost. A compact card. */
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
        $open = [];
        $spent = 0;
        foreach ($organisations as $organisation) {
            $open = [...$open, ...$this->workOrders->findOpen($organisation)];
            $spent += $this->workOrders->spent($organisation, $today->setDate((int) $today->format('Y'), 1, 1), $today->setTime(23, 59, 59));
        }

        return ['open' => $open, 'spent' => $spent, 'year' => $today->format('Y'), 'quoted' => array_sum(array_map(static fn ($w) => $w->getQuoteAmount() ?? 0, $open))];
    }
}
