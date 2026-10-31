<?php

namespace Base\Estate\Admin;

use Base\Admin\Config\Menu\MenuItem;
use Base\Estate\Entity\Application;
use Base\Estate\Entity\Associate;
use Base\Estate\Entity\Contractor;
use Base\Estate\Entity\Document;
use Base\Estate\Entity\Lease;
use Base\Estate\Entity\LeaseParty;
use Base\Estate\Entity\Mandate;
use Base\Estate\Entity\MandateExpense;
use Base\Estate\Entity\MandateFee;
use Base\Estate\Entity\MandatePayout;
use Base\Estate\Entity\Mandator;
use Base\Estate\Entity\Notice;
use Base\Estate\Entity\Ownership;
use Base\Estate\Entity\OwnershipCall;
use Base\Estate\Entity\OwnershipInvoice;
use Base\Estate\Entity\OwnershipPayment;
use Base\Estate\Entity\OwnershipShare;
use Base\Estate\Entity\Payment;
use Base\Estate\Entity\RentCall;
use Base\Estate\Entity\WorkOrder;
use Base\Estate\Enum\Activity;
use Base\Estate\Service\Activities;

/**
 * The back office items that belong to an activity, shown only when the
 * site runs it (estate.activities). A dashboard passes its items through
 * filter(), its labels, icons and order untouched:
 *
 *   public function configureWidgetItems(): iterable
 *   {
 *       return $this->activityMenu->filter($this->items());
 *   }
 *
 * Tenants, leases, rents, works and contractors (and their dashboard cards)
 * belong to the activities that let homes: assets, ownership, management;
 * the associates to assets; the shared ownerships - their shares, shared
 * invoices, calls for funds, statements and their dashboard card - to
 * ownership; the owners under management - their mandates, the expenses
 * charged to them, their payouts, statements and dashboard card - to
 * management. The rest - homes, buildings, the company, its team,
 * deadlines, the books - to every activity. A section left without items
 * is left out.
 */
class ActivityMenu
{
    /** Entity => the activities it belongs to. */
    private const ENTITIES = [
        Lease::class => Activity::RENTAL,
        LeaseParty::class => Activity::RENTAL,
        RentCall::class => Activity::RENTAL,
        Payment::class => Activity::RENTAL,
        WorkOrder::class => Activity::RENTAL,
        Contractor::class => Activity::RENTAL,
        Application::class => Activity::RENTAL,
        Notice::class => Activity::RENTAL,
        Document::class => Activity::RENTAL,
        Associate::class => [Activity::ASSETS],
        Ownership::class => [Activity::OWNERSHIP],
        OwnershipShare::class => [Activity::OWNERSHIP],
        OwnershipInvoice::class => [Activity::OWNERSHIP],
        OwnershipCall::class => [Activity::OWNERSHIP],
        OwnershipPayment::class => [Activity::OWNERSHIP],
        Mandator::class => [Activity::MANAGEMENT],
        Mandate::class => [Activity::MANAGEMENT],
        MandateFee::class => [Activity::MANAGEMENT],
        MandateExpense::class => [Activity::MANAGEMENT],
        MandatePayout::class => [Activity::MANAGEMENT],
    ];

    /** Route => the activities it belongs to. */
    private const ROUTES = [
        'estate_admin_messages' => Activity::RENTAL,
        'estate_admin_ownership_statements' => [Activity::OWNERSHIP],
        'estate_admin_management_statements' => [Activity::MANAGEMENT],
        'estate_admin_management_payouts' => [Activity::MANAGEMENT],
    ];

    /** Dashboard card (block name) => the activities it belongs to. */
    private const BLOCKS = [
        'estate_overview' => Activity::RENTAL,
        'estate_occupancy' => Activity::RENTAL,
        'estate_rents' => Activity::RENTAL,
        'estate_late' => Activity::RENTAL,
        'estate_works' => Activity::RENTAL,
        'estate_todo' => Activity::RENTAL,
        'estate_payments' => Activity::RENTAL,
        'estate_ownership' => [Activity::OWNERSHIP],
        'estate_management' => [Activity::MANAGEMENT],
    ];

    public function __construct(private readonly Activities $activities)
    {
    }

    /** @return list<Activity>|null the activities $item belongs to; null: every one */
    public static function activitiesOf(MenuItem $item): ?array
    {
        return self::ENTITIES[$item->getEntityFqcn() ?? ''] ?? self::ROUTES[$item->getRouteName() ?? ''] ?? self::BLOCKS[$item->getBlockName() ?? ''] ?? null;
    }

    public function allows(MenuItem $item): bool
    {
        $activities = self::activitiesOf($item);

        return null === $activities || $this->activities->any(...$activities);
    }

    /**
     * The items the site's activities allow, a section's sub-items too; a
     * section or a submenu none of whose items is left goes as well.
     *
     * @param iterable<MenuItem|mixed> $items
     *
     * @return \Generator<MenuItem|mixed>
     */
    public function filter(iterable $items): \Generator
    {
        foreach ($items as $item) {
            if (!$item instanceof MenuItem) {
                yield $item;
                continue;
            }
            if (!$this->allows($item)) {
                continue;
            }
            $subItems = $item->getSubItems();
            if ([] !== $subItems) {
                $kept = array_values(array_filter($subItems, fn ($subItem) => !$subItem instanceof MenuItem || $this->allows($subItem)));
                if ([] === $kept) {
                    continue;
                }
                if (\count($kept) !== \count($subItems)) {
                    $item->setSubItems($kept);
                }
            }
            yield $item;
        }
    }
}
