<?php

namespace Base\Estate\Admin\Widget\Dashboard;

use Base\Estate\Repository\ApplicationRepository;
use Base\Estate\Repository\LeaseRepository;
use Base\Estate\Repository\MessageRepository;
use Base\Estate\Repository\RentCallRepository;
use Base\Estate\Service\RentRevisions;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What waits for the management, as tinted rows each with its icon, a short
 * text and its action: the month's rents to call, applications to look at,
 * tenants' messages to read, rents to revise, insurance certificates
 * missing or run out, late rents to chase. Each count is one grouped query for every organisation.
 */
final class TodoWidgetType extends AbstractEstateWidgetType
{
    public function __construct(
        EntityManagerInterface $entityManager,
        private readonly RentRevisions $revisions,
        private readonly MessageRepository $messages,
        private readonly ApplicationRepository $applications,
        private readonly RentCallRepository $calls,
        private readonly LeaseRepository $leases,
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
        // Each organisation's counts, read for them all at once; listed organisation by organisation.
        $applications = $this->applications->countNewByOrganisation($organisations);
        $messages = $this->messages->unreadByOrganisation($organisations);
        $late = $this->calls->overdueByOrganisation($organisations, $today);
        foreach ($ids as $id) {
            if (($applications[$id] ?? 0) > 0) {
                $items[] = ['kind' => 'applications', 'icon' => 'fa-user-plus', 'count' => $applications[$id]];
            }
            if (($messages[$id] ?? 0) > 0) {
                $items[] = ['kind' => 'messages', 'icon' => 'fa-comments', 'count' => $messages[$id]];
            }
            if (isset($late[$id])) {
                $items[] = ['kind' => 'late', 'icon' => 'fa-hourglass-half', 'count' => $late[$id]['count'], 'call' => ['id' => $late[$id]['first']]];
            }
        }
        foreach ($this->revisions->due() as $revision) {
            if (\in_array($revision['lease']->getOrganisation()->getId(), $ids, true)) {
                $items[] = ['kind' => 'revision', 'icon' => 'fa-arrow-trend-up', 'lease' => $revision['lease'], 'on' => $revision['on']];
            }
        }
        foreach ($this->leases->findUninsured($organisations, $today) as $lease) {
            $items[] = ['kind' => 'insurance', 'icon' => 'fa-shield-halved', 'lease' => $lease];
        }
        // The month's rents, always: calling them is the management's first gesture of the month.
        array_unshift($items, ['kind' => 'call', 'icon' => 'fa-paper-plane', 'month' => $today]);

        return ['items' => $items];
    }
}
