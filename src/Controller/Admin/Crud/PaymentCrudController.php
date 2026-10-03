<?php

namespace Base\Estate\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Estate\Entity\Payment;
use Base\Estate\Enum\PaymentMethod;
use Base\Field\AssociationField;
use Base\Field\DateField;
use Base\Field\TextField;

class PaymentCrudController extends AbstractEstateCrudController
{
    public static function getEntityFqcn(): string { return Payment::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-money-bill-wave'; }

    public function configureActions(Actions $actions): Actions
    {
        return parent::configureActions($actions)->disable(Action::NEW, Action::EDIT);
    }

    public function configureFields(string $pageName): iterable
    {
        yield DateField::new('paidOn', 'Reçu le');
        yield AssociationField::new('rentCall', 'Loyer');
        yield $this->euros('amount', 'Montant');
        yield $this->enum('method', 'Moyen', PaymentMethod::class, fn (PaymentMethod $m) => LeaseCrudController::METHODS[$m->value]);
        yield TextField::new('reference', 'Référence');
    }
}
