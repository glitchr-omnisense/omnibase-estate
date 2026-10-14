<?php

namespace Base\Estate\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Estate\Entity\WorkOrder;
use Base\Estate\Enum\Urgency;
use Base\Estate\Enum\WorkOrderStatus;
use Base\Field\AssociationField;
use Base\Field\DateTimeField;
use Base\Field\TextareaField;
use Base\Field\TextField;

/** The work orders: "Confier" gives one to a contractor (and tells them), "Clôturer" sends its invoice to the books. */
class WorkOrderCrudController extends AbstractEstateCrudController
{
    public const STATUSES = ['reported' => 'Signalé', 'assigned' => 'Confié', 'scheduled' => 'Planifié', 'done' => 'Terminé', 'closed' => 'Clôturé', 'cancelled' => 'Annulé'];
    public const URGENCIES = ['low' => 'Peut attendre', 'normal' => 'Normal', 'urgent' => 'Urgent'];

    public static function getEntityFqcn(): string { return WorkOrder::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-screwdriver-wrench'; }

    public function configureActions(Actions $actions): Actions
    {
        $assign = Action::new('estateAssign', 'Confier', 'fa-solid fa-helmet-safety')
            ->linkToRoute('estate_admin_assign', fn (WorkOrder $w) => ['id' => $w->getId()])
            ->displayIf(fn (WorkOrder $w) => $w->getStatus()->isOpen() && WorkOrderStatus::DONE !== $w->getStatus());
        $close = Action::new('estateClose', 'Clôturer', 'fa-solid fa-flag-checkered')
            ->linkToRoute('estate_admin_close', fn (WorkOrder $w) => ['id' => $w->getId(), '_token' => $this->actionToken('estate_close', $w->getId())])
            ->displayIf(fn (WorkOrder $w) => WorkOrderStatus::DONE === $w->getStatus());

        return parent::configureActions($actions)
            ->add(Action::INDEX, $assign)->add(Action::DETAIL, $assign)->add(Action::INDEX, $close)->add(Action::DETAIL, $close);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('title', 'Problème')->setColumns(6);
        yield AssociationField::new('unit', 'Bien')->setFormType(\Symfony\Bridge\Doctrine\Form\Type\EntityType::class)->setFormTypeOptions(['class' => \Base\Estate\Entity\Unit::class])->setColumns(6);
        yield TextField::new('category', 'Catégorie')->setColumns(3)->hideOnIndex();
        yield $this->enum('urgency', 'Urgence', Urgency::class, fn (Urgency $u) => self::URGENCIES[$u->value])->setColumns(3);
        yield $this->enum('status', 'État', WorkOrderStatus::class, fn (WorkOrderStatus $s) => self::STATUSES[$s->value])->setColumns(3);
        yield AssociationField::new('contractor', 'Artisan')->setColumns(3)->setRequired(false);
        yield DateTimeField::new('visitAt', 'Passage')->setRequired(false)->setColumns(3);
        yield $this->euros('quoteAmount', 'Devis')->setRequired(false)->setColumns(3)->hideOnIndex();
        yield $this->euros('invoiceAmount', 'Facture')->setRequired(false)->setColumns(3);
        yield TextField::new('invoiceNumber', 'N° de facture')->setRequired(false)->setColumns(3)->hideOnIndex();
        yield TextareaField::new('description', 'Description')->setColumns(12)->hideOnIndex();
        yield TextareaField::new('report', 'Compte rendu')->setRequired(false)->setColumns(12)->hideOnIndex();
        yield DateTimeField::new('createdAt', 'Signalé le')->hideOnForm();
    }
}
