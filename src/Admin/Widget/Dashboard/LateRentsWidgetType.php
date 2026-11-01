<?php

namespace Base\Estate\Admin\Widget\Dashboard;

use Base\Estate\Entity\RentCall;
use Base\Estate\Repository\RentCallRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Late rents: what is owed and by whom, oldest first, each with the way to record its payment; then
 * the month's rents and where each stands - paid, due on its day, late -, so that a card with
 * nothing late still says how the month goes. The month is the current one once its rents are
 * called, the last one before that. A compact card.
 */
final class LateRentsWidgetType extends AbstractEstateWidgetType
{
    protected const COMPACT = true;

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
        $late = [];
        foreach ($organisations as $organisation) {
            foreach ($this->calls->findOverdue($today, $organisation) as $call) {
                if (!$call->isPaid()) {
                    $late[] = $call;
                }
            }
        }
        usort($late, static fn (RentCall $a, RentCall $b) => $a->getDueOn() <=> $b->getDueOn());

        $month = $today->modify('first day of this month');
        $calls = $this->monthCalls($organisations, $month);
        if (!$calls) {
            $month = $month->modify('-1 month');
            $calls = $this->monthCalls($organisations, $month);
        }
        // The month's own late rents are already listed above it.
        $lateIds = array_map(static fn (RentCall $c) => $c->getId(), $late);
        $rows = array_values(array_filter($calls, static fn (RentCall $c) => !\in_array($c->getId(), $lateIds, true)));
        usort($rows, static fn (RentCall $a, RentCall $b) => [$a->isPaid(), $a->getDueOn()] <=> [$b->isPaid(), $b->getDueOn()]);

        return [
            'late' => $late,
            'total' => array_sum(array_map(static fn (RentCall $c) => $c->getRemaining(), $late)),
            'today' => $today,
            'month' => $month,
            'monthCalls' => $rows,
            'monthCalled' => array_sum(array_map(static fn (RentCall $c) => $c->getTotal(), $calls)),
            'monthReceived' => array_sum(array_map(static fn (RentCall $c) => $c->getPaid(), $calls)),
        ];
    }

    /** @param list<\Base\Estate\Entity\Organisation> $organisations @return list<RentCall> */
    private function monthCalls(array $organisations, \DateTimeImmutable $month): array
    {
        $calls = [];
        foreach ($organisations as $organisation) {
            $calls = [...$calls, ...$this->calls->findForOrganisation($organisation, $month, $month)];
        }

        return $calls;
    }
}
