<?php

namespace Base\Estate\Admin\Widget;

use Base\Admin\Config\Menu\MenuItem;
use Base\Admin\Widget\DashboardWidgetTypeInterface;
use Base\Estate\Entity\Payment;
use Base\Estate\Enum\PaymentMethod;
use Base\Estate\Service\OnlinePayments;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Money in: the latest rents received and how - transfer, card or SEPA
 * debit through omnitrade - the month's total per way, and whether online
 * payment is on.
 */
final class PaymentsWidgetType implements DashboardWidgetTypeInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OnlinePayments $online,
    ) {
    }

    public static function getName(): string
    {
        return 'estate_payments';
    }

    public function getTemplate(): string
    {
        return '@Estate/admin/widget/payments.html.twig';
    }

    public function getTemplateVars(MenuItem $widget): array
    {
        $since = new \DateTimeImmutable('first day of this month midnight');
        $month = $this->entityManager->getRepository(Payment::class)->createQueryBuilder('p')
            ->where('p.paidOn >= :since')->setParameter('since', $since, 'date_immutable')->getQuery()->getResult();
        $byMethod = [];
        foreach (PaymentMethod::cases() as $method) {
            $byMethod[$method->value] = 0;
        }
        foreach ($month as $payment) {
            $byMethod[$payment->getMethod()->value] += $payment->getAmount();
        }

        return [
            'latest' => $this->entityManager->getRepository(Payment::class)->findBy([], ['paidOn' => 'DESC', 'id' => 'DESC'], 8),
            'byMethod' => array_filter($byMethod),
            'total' => array_sum($byMethod),
            'online' => $this->online->isAvailable(),
            'methods' => ['transfer' => 'Virement', 'card' => 'Carte', 'sepa_debit' => 'Prélèvement SEPA', 'cheque' => 'Chèque', 'cash' => 'Espèces'],
        ];
    }
}
