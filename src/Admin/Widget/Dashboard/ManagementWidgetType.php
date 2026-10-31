<?php

namespace Base\Estate\Admin\Widget\Dashboard;

use Base\Estate\Enum\Activity;
use Base\Estate\Repository\MandatePayoutRepository;
use Base\Estate\Repository\MandateRepository;
use Base\Estate\Service\Activities;
use Base\Estate\Service\Management;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Property management for third parties: what the owners are due at the
 * end of last month (the payouts to issue), the payouts issued and not
 * paid yet, the statements of the last closed period still to send, the
 * mandates ending within two months. Shown only when the site runs the
 * `management` activity (ActivityMenu).
 */
final class ManagementWidgetType extends AbstractEstateWidgetType
{
    public function __construct(
        EntityManagerInterface $entityManager,
        private readonly Management $management,
        private readonly MandateRepository $mandates,
        private readonly MandatePayoutRepository $payouts,
        private readonly Activities $activities,
    ) {
        parent::__construct($entityManager);
    }

    public static function getName(): string { return 'estate_management'; }
    public function getDefaultLabel(): string { return 'estate_widget.management'; }
    public function getDefaultIcon(): ?string { return 'fa-solid fa-user-tie'; }
    protected function template(): string { return 'management'; }

    protected function data(array $organisations, array $params): array
    {
        if (!$this->activities->has(Activity::MANAGEMENT)) {
            return ['due' => [], 'dueTotal' => 0, 'unpaid' => [], 'toSend' => [], 'ending' => [], 'on' => null];
        }
        $today = new \DateTimeImmutable('today');
        $on = $today->modify('last day of last month');
        $due = $this->management->payoutsDue($on);

        return [
            'on' => $on,
            'due' => $due,
            'dueTotal' => array_sum(array_column($due, 'amount')),
            'unpaid' => $this->payouts->findUnpaid(),
            'toSend' => $this->management->statementsToSend($today),
            'ending' => $this->mandates->ending($today, $today->modify('+2 months')),
        ];
    }
}
