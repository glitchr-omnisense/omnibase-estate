<?php

namespace Base\Estate\Admin\Widget\Dashboard;

use Base\Estate\Service\Transactions;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Sales for third parties: the quarter's fees (invoiced at the actes and
 * expected from those planned), the sales going on with their next step
 * and date, the offers waiting for an answer, the mandates ending within a
 * month without a sale. Shown only when the site runs the `transaction`
 * activity (ActivityMenu); Transactions answers nothing otherwise.
 */
final class TransactionWidgetType extends AbstractEstateWidgetType
{
    public function __construct(EntityManagerInterface $entityManager, private readonly Transactions $transactions)
    {
        parent::__construct($entityManager);
    }

    public static function getName(): string { return 'estate_transaction'; }
    public function getDefaultLabel(): string { return 'estate_widget.transaction'; }
    public function getDefaultIcon(): ?string { return 'fa-solid fa-sign-hanging'; }
    protected function template(): string { return 'transaction'; }

    protected function data(array $organisations, array $params): array
    {
        $today = new \DateTimeImmutable('today');

        return [
            'fees' => $this->transactions->feesOfQuarter($today),
            'sales' => $this->transactions->salesInProgress(),
            'offers' => $this->transactions->offersWaiting(),
            'ending' => $this->transactions->ending($today, $today->modify('+1 month')),
            'active' => $this->transactions->isActive(),
        ];
    }
}
