<?php

namespace Base\Estate\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Config\Crud;
use Base\Estate\Entity\Contractor;
use Base\Estate\Entity\Mandate;
use Base\Estate\Entity\MandateExpense;
use Base\Estate\Entity\Unit;
use Base\Estate\Service\Management;
use Base\Field\AssociationField;
use Base\Field\DateField;
use Base\Field\DateTimeField;
use Base\Field\FileField;
use Base\Field\TextField;
use Base\Database\Repository\Repositories;
use Base\Contract\UnitOfWork;
use Base\Field\Type\SelectType;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * The expenses charged to the owners: the work orders' invoices on their
 * homes come by themselves when the work order is closed; the others - the
 * syndic's calls, an insurance, the property tax paid for them - are
 * entered here. Saved, an expense is posted to the owner's account; posted,
 * it is no longer edited.
 */
class MandateExpenseCrudController extends AbstractEstateCrudController
{
    private ?Management $management = null;
    private ?Repositories $expenseRepositories = null;

    #[Required]
    public function setManagement(Management $management, Repositories $repositories): void
    {
        $this->management = $management;
        $this->expenseRepositories = $repositories;
    }

    public static function getEntityFqcn(): string { return MandateExpense::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-receipt'; }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setDefaultSort(['date' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return parent::configureActions($actions)
            ->update(Action::INDEX, Action::EDIT, fn (Action $action) => $action->displayIf(fn (MandateExpense $e) => !$e->isPosted()))
            ->update(Action::DETAIL, Action::EDIT, fn (Action $action) => $action->displayIf(fn (MandateExpense $e) => !$e->isPosted()));
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('mandate', 'Mandat')->setFormType(SelectType::class)->setFormTypeOptions(['class' => Mandate::class])->setColumns(4);
        yield TextField::new('label', 'Objet')->setColumns(5);
        yield DateField::new('date', 'Date')->setColumns(3);
        yield AssociationField::new('unit', 'Bien')->setFormType(SelectType::class)->setFormTypeOptions(['class' => Unit::class, 'required' => false])->setRequired(false)->setColumns(4)->hideOnIndex();
        yield AssociationField::new('contractor', 'Artisan')->setFormType(SelectType::class)->setFormTypeOptions(['class' => Contractor::class, 'required' => false])->setRequired(false)->setColumns(4)->hideOnIndex();
        yield TextField::new('supplierName', 'Ou fournisseur')->setRequired(false)->setColumns(4)->hideOnIndex()->setHelp('Le syndic, un assureur, le Trésor public.');
        yield TextField::new('supplier', 'Fournisseur')->onlyOnIndex();
        yield TextField::new('number', 'N° de facture')->setRequired(false)->setColumns(4)->hideOnIndex();
        yield $this->euros('amount', 'Montant TTC')->setColumns(4)->setHelp('Le propriétaire supporte la TVA : le TTC est imputé à son compte.');
        yield FileField::new('file', 'Facture (fichier)')->setRequired(false)->setColumns(4)->hideOnIndex();
        yield AssociationField::new('workOrder', 'Intervention')->hideOnForm()->hideOnIndex();
        yield DateTimeField::new('postedAt', 'Imputée le')->hideOnForm();
    }

    /** A new expense opens on the first mandate (an ordinary field to change). */
    public function createEntity(string $entityFqcn): MandateExpense
    {
        $expense = new MandateExpense();
        if (null !== $mandate = $this->expenseRepositories?->of(Mandate::class)->findOneBy([], ['id' => 'ASC'])) {
            $expense->setMandate($mandate);
        }

        return $expense;
    }

    public function persistRecord(UnitOfWork $unitOfWork, object $entity): void
    {
        parent::persistRecord($unitOfWork, $entity);
        if ($this->management?->isActive()) {
            $this->management->postExpense($entity);
        }
    }

    public function updateRecord(UnitOfWork $unitOfWork, object $entity): void
    {
        parent::updateRecord($unitOfWork, $entity);
        if ($this->management?->isActive()) {
            $this->management->postExpense($entity);
        }
    }
}
