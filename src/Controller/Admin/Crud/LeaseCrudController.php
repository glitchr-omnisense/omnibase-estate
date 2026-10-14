<?php

namespace Base\Estate\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Estate\Entity\Lease;
use Base\Estate\Enum\LeaseStatus;
use Base\Estate\Enum\PaymentMethod;
use Base\Field\AssociationField;
use Base\Field\DateField;
use Base\Field\IntegerField;
use Base\Field\TextareaField;
use Base\Field\TextField;

/** The leases: who, which home, the rent and its index, the insurance - and the yearly rent revision. */
class LeaseCrudController extends AbstractEstateCrudController
{
    public const STATUSES = ['active' => 'En cours', 'notice' => 'Congé donné', 'ended' => 'Terminé'];
    public const METHODS = ['transfer' => 'Virement', 'card' => 'Carte', 'sepa_debit' => 'Prélèvement SEPA', 'cheque' => 'Chèque', 'cash' => 'Espèces'];

    public static function getEntityFqcn(): string { return Lease::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-file-signature'; }

    public function configureActions(Actions $actions): Actions
    {
        $revise = Action::new('estateRevise', 'Réviser le loyer', 'fa-solid fa-arrow-trend-up')
            ->linkToRoute('estate_admin_revise', fn (Lease $l) => ['id' => $l->getId()])
            ->displayIf(fn (Lease $l) => LeaseStatus::ENDED !== $l->getStatus());

        return parent::configureActions($actions)->add(Action::INDEX, $revise)->add(Action::DETAIL, $revise);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('reference', 'Référence')->setColumns(3);
        yield AssociationField::new('unit', 'Bien')->setFormType(\Symfony\Bridge\Doctrine\Form\Type\EntityType::class)->setFormTypeOptions(['class' => \Base\Estate\Entity\Unit::class])->setColumns(5);
        yield TextField::new('tenantNames', 'Locataires')->onlyOnIndex();
        yield $this->enum('status', 'État', LeaseStatus::class, fn (LeaseStatus $s) => self::STATUSES[$s->value])->setColumns(4);
        yield DateField::new('startOn', 'Début')->setColumns(3);
        yield DateField::new('endOn', 'Fin')->setRequired(false)->setColumns(3)->hideOnIndex();
        yield DateField::new('noticeOn', 'Congé reçu le')->setRequired(false)->setColumns(3)->hideOnIndex();
        yield DateField::new('insuranceUntil', 'Assurance jusqu\'au')->setRequired(false)->setColumns(3)->hideOnIndex();
        yield $this->euros('rent', 'Loyer HC')->setColumns(3);
        yield $this->euros('charges', 'Charges')->setColumns(3)->hideOnIndex();
        yield $this->euros('deposit', 'Dépôt de garantie')->setColumns(3)->hideOnIndex();
        yield IntegerField::new('dueDay', 'Jour d\'échéance')->setColumns(3)->hideOnIndex();
        yield $this->enum('paymentMethod', 'Paiement', PaymentMethod::class, fn (PaymentMethod $m) => self::METHODS[$m->value])->setColumns(3)->hideOnIndex();
        yield TextField::new('indexQuarter', 'IRL de référence (trimestre)')->setRequired(false)->setColumns(3)->hideOnIndex()->setHelp('« T2 2025 » : celui du bail, ou de la dernière révision.');
        yield TextField::new('indexValue', 'IRL de référence (valeur)')->setRequired(false)->setColumns(3)->hideOnIndex()->setHelp('La valeur publiée par l\'INSEE pour ce trimestre.');
        yield DateField::new('revisedOn', 'Dernière révision')->setRequired(false)->setColumns(3)->hideOnIndex();
        yield TextareaField::new('notes', 'Notes')->setRequired(false)->setColumns(12)->hideOnIndex();
    }
}
