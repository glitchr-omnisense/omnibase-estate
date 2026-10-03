<?php

namespace Base\Estate\Admin\Widget;

use Base\Admin\Config\Menu\MenuItem;
use Base\Admin\Widget\DashboardWidgetTypeInterface;
use Base\Estate\Entity\Lease;
use Base\Estate\Entity\Organisation;
use Base\Estate\Enum\LeaseStatus;
use Base\Estate\Repository\ApplicationRepository;
use Base\Estate\Repository\MessageRepository;
use Base\Estate\Service\Figures;
use Base\Estate\Service\RentRevisions;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The company at a glance, first on the dashboard: what is let, the rents
 * of the year called and received, what is late, what the works cost, the
 * last six months side by side - and what waits: rents to collect, work
 * orders to give, applications to look at, rents to revise, tenants' messages to answer, insurance
 * certificates missing or run out.
 */
final class OverviewWidgetType implements DashboardWidgetTypeInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Figures $figures,
        private readonly RentRevisions $revisions,
        private readonly MessageRepository $messages,
        private readonly ApplicationRepository $applications,
    ) {
    }

    public static function getName(): string
    {
        return 'estate_overview';
    }

    public function getTemplate(): string
    {
        return '@Estate/admin/widget/overview.html.twig';
    }

    public function getTemplateVars(MenuItem $widget): array
    {
        $organisations = $this->entityManager->getRepository(Organisation::class)->findBy([], ['name' => 'ASC']);

        $revisions = $this->revisions->due();
        $today = new \DateTimeImmutable('today');

        return ['companies' => array_map(fn (Organisation $o) => [
            'organisation' => $o,
            'figures' => $this->figures->of($o),
            'revisions' => array_values(array_filter($revisions, fn ($r) => $r['lease']->getOrganisation()->getId() === $o->getId())),
            'unread' => $this->messages->unreadByManagement($o),
            'applications' => $this->applications->countNew($o),
            'uninsured' => array_values(array_filter(
                $this->entityManager->getRepository(Lease::class)->findBy(['status' => LeaseStatus::ACTIVE]),
                fn (Lease $l) => $l->getOrganisation()->getId() === $o->getId() && (null === $l->getInsuranceUntil() || $l->getInsuranceUntil() < $today),
            )),
        ], $organisations)];
    }
}
