<?php

namespace Base\Estate\Admin\Widget\Dashboard;

use Base\Estate\Entity\Lease;
use Base\Estate\Enum\LeaseStatus;
use Base\Estate\Repository\ApplicationRepository;
use Base\Estate\Repository\MessageRepository;
use Base\Estate\Repository\RentCallRepository;
use Base\Estate\Service\RentRevisions;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What waits for the management, as tinted rows each with its icon, a short
 * text and its action: the month's rents to call, applications to look at,
 * tenants' messages to read, rents to revise, insurance certificates
 * missing or run out, late rents to chase.
 */
final class TodoWidgetType extends AbstractEstateWidgetType
{
    public function __construct(
        EntityManagerInterface $entityManager,
        private readonly RentRevisions $revisions,
        private readonly MessageRepository $messages,
        private readonly ApplicationRepository $applications,
        private readonly RentCallRepository $calls,
    ) {
        parent::__construct($entityManager);
    }

    public static function getName(): string { return 'estate_todo'; }
    public function getDefaultLabel(): string { return 'estate_widget.todo'; }
    public function getDefaultIcon(): ?string { return 'fa-solid fa-list-check'; }
    protected function template(): string { return 'todo'; }

    protected function data(array $organisations, array $params): array
    {
        $today = new \DateTimeImmutable('today');
        $items = [];
        $ids = array_map(static fn ($o) => $o->getId(), $organisations);
        foreach ($organisations as $organisation) {
            if (($count = $this->applications->countNew($organisation)) > 0) {
                $items[] = ['kind' => 'applications', 'icon' => 'fa-user-plus', 'count' => $count];
            }
            if (($count = $this->messages->unreadByManagement($organisation)) > 0) {
                $items[] = ['kind' => 'messages', 'icon' => 'fa-comments', 'count' => $count];
            }
            $late = array_filter($this->calls->findOverdue($today, $organisation), static fn ($c) => !$c->isPaid());
            if ([] !== $late) {
                $items[] = ['kind' => 'late', 'icon' => 'fa-hourglass-half', 'count' => \count($late), 'call' => reset($late)];
            }
        }
        foreach ($this->revisions->due() as $revision) {
            if (\in_array($revision['lease']->getOrganisation()->getId(), $ids, true)) {
                $items[] = ['kind' => 'revision', 'icon' => 'fa-arrow-trend-up', 'lease' => $revision['lease'], 'on' => $revision['on']];
            }
        }
        foreach ($this->entityManager->getRepository(Lease::class)->findBy(['status' => LeaseStatus::ACTIVE]) as $lease) {
            if (\in_array($lease->getOrganisation()->getId(), $ids, true) && (null === $lease->getInsuranceUntil() || $lease->getInsuranceUntil() < $today)) {
                $items[] = ['kind' => 'insurance', 'icon' => 'fa-shield-halved', 'lease' => $lease];
            }
        }
        // The month's rents, always: calling them is the management's first gesture of the month.
        array_unshift($items, ['kind' => 'call', 'icon' => 'fa-paper-plane', 'month' => $today]);

        return ['items' => $items];
    }
}
