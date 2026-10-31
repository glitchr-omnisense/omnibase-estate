<?php

namespace Base\Estate\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Config\Crud;
use Base\Estate\Entity\Buyer;
use Base\Estate\Entity\Offer;
use Base\Estate\Entity\SaleMandate;
use Base\Estate\Enum\OfferStatus;
use Base\Field\AssociationField;
use Base\Field\BooleanField;
use Base\Field\DateField;
use Base\Field\TextareaField;
use Base\Field\TextField;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;

/**
 * The buyers' offers: amount, conditions (a loan, until when it holds),
 * where they stand. Accepted, countered, refused or withdrawn from the
 * mandate's page (« Suivi »), where the sale then follows its steps; an
 * offer answered is no longer edited here.
 */
class OfferCrudController extends AbstractEstateCrudController
{
    public static function getEntityFqcn(): string { return Offer::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-hand-holding-dollar'; }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setDefaultSort(['madeOn' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $follow = Action::new('estateOfferFollow', 'Suivi', 'fa-solid fa-route')
            ->linkToRoute('estate_admin_transaction_mandate', fn (Offer $o) => ['id' => $o->getMandate()->getId()]);

        return parent::configureActions($actions)->add(Action::INDEX, $follow)->add(Action::DETAIL, $follow)
            ->update(Action::INDEX, Action::EDIT, fn (Action $action) => $action->displayIf(fn (Offer $o) => OfferStatus::MADE === $o->getStatus()))
            ->update(Action::DETAIL, Action::EDIT, fn (Action $action) => $action->displayIf(fn (Offer $o) => OfferStatus::MADE === $o->getStatus()));
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('mandate', 'Mandat')->setFormType(EntityType::class)->setFormTypeOptions(['class' => SaleMandate::class])->setColumns(5);
        yield AssociationField::new('buyer', 'Acquéreur')->setFormType(EntityType::class)->setFormTypeOptions(['class' => Buyer::class])->setColumns(4);
        yield DateField::new('madeOn', 'Faite le')->setColumns(3);
        yield $this->euros('amount', 'Montant')->setColumns(4);
        yield BooleanField::new('loanCondition', 'Sous condition de prêt')->setColumns(4)->hideOnIndex();
        yield $this->euros('loanAmount', 'Prêt de')->setRequired(false)->setColumns(4)->hideOnIndex();
        yield DateField::new('validUntil', 'Valable jusqu\'au')->setRequired(false)->setColumns(4)->hideOnIndex();
        yield TextareaField::new('conditions', 'Autres conditions')->setRequired(false)->setColumns(8)->hideOnIndex();
        yield TextField::new('status', 'État')->hideOnForm()->formatValue(fn ($status) => $status instanceof OfferStatus ? $status->label() : $status);
        yield $this->euros('counterAmount', 'Contre-proposition')->hideOnForm()->hideOnIndex();
    }

    public function createEntity(string $entityFqcn): Offer
    {
        return $this->prefillSale(new Offer());
    }
}
