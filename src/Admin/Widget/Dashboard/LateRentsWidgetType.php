<?php

namespace Base\Estate\Admin\Widget\Dashboard;

use Base\Estate\Repository\RentCallRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Late rents: what is owed and by whom, oldest first, each with the way to record its payment; then
 * the month's rents and where each stands - paid, due on its day, late -, so that a card with
 * nothing late still says how the month goes. The month is the current one once its rents are
 * called, the last one before that. A compact card. Its figures are sums in the database for every
 * organisation together; only the rows it lists are read as entities.
 */
final class LateRentsWidgetType extends AbstractEstateWidgetType
{
    protected const COMPACT = true;
    /** The rows the card lists, of each list: the counts and sums are of them all. */
    private const ROWS = 4;

    public function __construct(EntityManagerInterface $entityManager, private readonly RentCallRepository $calls)
    {
        parent::__construct($entityManager);
    }

    public static function getName(): string { return 'estate_late'; }
    public function getDefaultLabel(): string { return 'estate_widget.late'; }
    public function getDefaultIcon(): ?string { return 'fa-solid fa-hourglass-half'; }
    protected function template(): string { return 'late'; }

    protected function data(array $organisations, array $params): array
    {
        $today = new \DateTimeImmutable('today');
        $late = $this->calls->overdueTotals($organisations, $today);

        $month = $today->modify('first day of this month');
        $totals = $this->monthTotals($organisations, $month);
        if (0 === $totals['count']) {
            $month = $month->modify('-1 month');
            $totals = $this->monthTotals($organisations, $month);
        }

        return [
            'late' => $this->calls->findOverdueFirst($organisations, $today, self::ROWS),
            'lateCount' => $late['count'],
            'total' => $late['total'],
            'today' => $today,
            'month' => $month,
            // The month's own late rents are already listed above it.
            'monthCalls' => $this->calls->findInMonthNotOverdue($organisations, $month, $today, self::ROWS),
            'monthCalled' => $totals['called'],
            'monthReceived' => $totals['received'],
        ];
    }

    /** @param list<\Base\Estate\Entity\Organisation> $organisations @return array{called: int, received: int, count: int} */
    private function monthTotals(array $organisations, \DateTimeImmutable $month): array
    {
        return $this->calls->monthTotals($organisations, $month, $month)[$month->format('Y-m')] ?? ['called' => 0, 'received' => 0, 'count' => 0];
    }
}
