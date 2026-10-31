<?php

namespace Base\Estate\Admin\Widget\Dashboard;

use Base\Estate\Entity\Ownership;
use Base\Estate\Entity\OwnershipCall;
use Base\Estate\Repository\OwnershipCallRepository;
use Base\Estate\Service\Activities;
use Base\Estate\Enum\Activity;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The shared ownerships: each one's calls for funds still to be paid and
 * those late, whether its shares add up; the calls late, oldest first,
 * each with the way to record its payment. Shown only when the site runs
 * the `ownership` activity (ActivityMenu).
 */
final class OwnershipWidgetType extends AbstractEstateWidgetType
{
    public function __construct(EntityManagerInterface $entityManager, private readonly OwnershipCallRepository $calls, private readonly Activities $activities)
    {
        parent::__construct($entityManager);
    }

    public static function getName(): string { return 'estate_ownership'; }
    public function getDefaultLabel(): string { return 'estate_widget.ownership'; }
    public function getDefaultIcon(): ?string { return 'fa-solid fa-people-roof'; }
    protected function template(): string { return 'ownership'; }

    protected function data(array $organisations, array $params): array
    {
        if (!$this->activities->has(Activity::OWNERSHIP)) {
            return ['rows' => [], 'late' => [], 'due' => 0, 'lateTotal' => 0];
        }
        $today = new \DateTimeImmutable('today');
        $open = $this->calls->findOpen();
        $rows = [];
        foreach ($this->entityManager->getRepository(Ownership::class)->findBy([], ['name' => 'ASC']) as $ownership) {
            $mine = array_filter($open, fn (OwnershipCall $c) => $c->getOwnership()->getId() === $ownership->getId());
            $late = array_filter($mine, fn (OwnershipCall $c) => $c->isLate($today));
            $rows[] = [
                'ownership' => $ownership,
                'due' => array_sum(array_map(fn (OwnershipCall $c) => $c->getRemaining(), $mine)),
                'late' => \count($late),
                'balanced' => $ownership->isBalanced(),
            ];
        }
        $late = array_values(array_filter($open, fn (OwnershipCall $c) => $c->isLate($today)));

        return [
            'rows' => $rows,
            'late' => $late,
            'due' => array_sum(array_column($rows, 'due')),
            'lateTotal' => array_sum(array_map(fn (OwnershipCall $c) => $c->getRemaining(), $late)),
        ];
    }
}
