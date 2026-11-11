<?php

namespace Base\Estate\Admin\Widget;

use Base\Admin\Config\Menu\MenuItem;
use Base\Admin\Widget\PaletteDashboardWidgetTypeInterface;
use Base\Estate\Enum\PaymentMethod;
use Base\Estate\Repository\PaymentRepository;
use Base\Estate\Service\OnlinePayments;

/**
 * Money in: the latest rents received and how - transfer, card or SEPA
 * debit through omnitrade - the month's total per way, and whether online
 * payment is on. A palette card, in omnibase/admin's dashboard card.
 */
final class PaymentsWidgetType implements PaletteDashboardWidgetTypeInterface
{
    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly OnlinePayments $online,
    ) {
    }

    public static function getName(): string
    {
        return 'estate_payments';
    }

    public function getDefaultLabel(): string
    {
        return 'estate_widget.payments';
    }

    public function getDefaultIcon(): ?string
    {
        return 'fa-solid fa-money-bill-wave';
    }

    public function getTemplate(): string
    {
        return '@Estate/admin/widget/dashboard/payments.html.twig';
    }

    public function getTemplateVars(MenuItem $widget): array
    {
        $byMethod = [];
        foreach (PaymentMethod::cases() as $method) {
            $byMethod[$method->value] = 0;
        }
        // The month's total per way, summed in the database.
        foreach ($this->payments->totalsByMethod(new \DateTimeImmutable('first day of this month midnight')) as $method => $amount) {
            $byMethod[$method] = ($byMethod[$method] ?? 0) + $amount;
        }

        return [
            'card' => 'payments',
            'compact' => false,
            'latest' => $this->payments->findBy([], ['paidOn' => 'DESC', 'id' => 'DESC'], 4),
            'byMethod' => array_filter($byMethod),
            'total' => array_sum($byMethod),
            'online' => $this->online->isAvailable(),
            'methods' => ['transfer' => 'Virement', 'card' => 'Carte', 'sepa_debit' => 'Prélèvement SEPA', 'cheque' => 'Chèque', 'cash' => 'Espèces'],
        ];
    }
}
