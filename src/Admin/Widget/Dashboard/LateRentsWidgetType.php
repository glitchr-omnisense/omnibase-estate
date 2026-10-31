<?php

namespace Base\Estate\Admin\Widget\Dashboard;

use Base\Estate\Entity\RentCall;
use Base\Estate\Repository\RentCallRepository;
use Doctrine\ORM\EntityManagerInterface;

/** Late rents: what is owed and by whom, oldest first, each with the way to record its payment. A compact card. */
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

        return ['late' => $late, 'total' => array_sum(array_map(static fn (RentCall $c) => $c->getRemaining(), $late)), 'today' => $today];
    }
}
