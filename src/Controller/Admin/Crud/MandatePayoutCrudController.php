<?php

namespace Base\Estate\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Config\Crud;
use Base\Estate\Entity\MandatePayout;
use Base\Estate\Enum\PayoutStatus;
use Base\Field\AssociationField;
use Base\Field\DateField;
use Base\Field\DateTimeField;
use Base\Field\TextField;

/**
 * The owners' payouts, as issued from « Reversements »
 * (estate_admin_management_payouts: what each one is due, the SEPA file):
 * their reference, amount, IBAN and where they stand; « Payé » records one
 * the bank reconciliation did not match. Not typed by hand.
 */
class MandatePayoutCrudController extends AbstractEstateCrudController
{
    public static function getEntityFqcn(): string { return MandatePayout::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-money-bill-transfer'; }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setDefaultSort(['issuedOn' => 'DESC', 'id' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $paid = Action::new('estateMandatePayoutPaid', 'Payé', 'fa-solid fa-check')
            ->linkToRoute('estate_admin_management_payout_paid', fn (MandatePayout $p) => ['id' => $p->getId()])
            ->displayIf(fn (MandatePayout $p) => !$p->isPaid());
        $file = Action::new('estateMandatePayoutsFile', 'Fichier SEPA', 'fa-solid fa-file-export')
            ->linkToRoute('estate_admin_management_payouts', fn () => [])->createAsGlobalAction();

        return parent::configureActions($actions)->disable(Action::NEW, Action::EDIT)
            ->add(Action::INDEX, $paid)->add(Action::DETAIL, $paid)->add(Action::INDEX, $file);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('reference', 'Référence');
        yield AssociationField::new('mandate', 'Mandat');
        yield DateField::new('balanceOn', 'Solde au');
        yield $this->euros('balance', 'Solde')->hideOnIndex();
        yield $this->euros('reserve', 'Réserve')->hideOnIndex();
        yield $this->euros('amount', 'Montant');
        yield TextField::new('iban', 'IBAN')->hideOnIndex();
        yield TextField::new('status', 'État')->formatValue(fn ($status) => $status instanceof PayoutStatus ? $status->label() : $status);
        yield DateField::new('issuedOn', 'Émis le')->hideOnIndex();
        yield DateTimeField::new('exportedAt', 'Fichier SEPA le')->hideOnIndex();
        yield DateField::new('paidOn', 'Payé le');
        yield TextField::new('paidReference', 'Payé sous')->hideOnIndex();
    }
}
