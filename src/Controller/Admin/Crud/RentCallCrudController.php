<?php

namespace Base\Estate\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Estate\Entity\RentCall;
use Base\Estate\Enum\RentStatus;
use Base\Field\AssociationField;
use Base\Field\DateField;
use Base\Field\TextField;

/** The rents called: read-only, settled by "Encaisser" (a payment) or by the bank reconciliation. */
class RentCallCrudController extends AbstractEstateCrudController
{
    public const STATUSES = ['called' => 'Appelé', 'partial' => 'Partiel', 'paid' => 'Payé', 'late' => 'En retard'];

    public static function getEntityFqcn(): string { return RentCall::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-file-invoice-dollar'; }

    public function configureActions(Actions $actions): Actions
    {
        $collect = Action::new('estateCollect', 'Encaisser', 'fa-solid fa-hand-holding-dollar')
            ->linkToRoute('estate_admin_payment', fn (RentCall $c) => ['id' => $c->getId()])
            ->displayIf(fn (RentCall $c) => !$c->isPaid());
        $call = Action::new('estateCall', 'Appeler les loyers du mois', 'fa-solid fa-paper-plane')
            ->linkToRoute('estate_admin_rents_call', fn () => ['_token' => $this->actionToken('estate_rents_call', 0)])->createAsGlobalAction();

        return parent::configureActions($actions)->disable(Action::NEW, Action::EDIT)
            ->add(Action::INDEX, $collect)->add(Action::DETAIL, $collect)->add(Action::INDEX, $call);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('reference', 'Référence')->onlyOnDetail();
        yield AssociationField::new('lease', 'Bail');
        yield DateField::new('dueOn', 'Échéance');
        yield $this->euros('total', 'Montant');
        yield $this->euros('paid', 'Reçu');
        yield $this->enum('status', 'État', RentStatus::class, fn (RentStatus $s) => self::STATUSES[$s->value]);
        yield TextField::new('receiptNumber', 'Quittance')->setRequired(false);
    }
}
