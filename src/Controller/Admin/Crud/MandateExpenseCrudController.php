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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
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
    private ?EntityManagerInterface $expenseEntityManager = null;

    #[Required]
    public function setManagement(Management $management, EntityManagerInterface $entityManager): void
    {
        $this->management = $management;
        $this->expenseEntityManager = $entityManager;
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
        yield AssociationField::new('mandate', 'Mandat')->setFormType(EntityType::class)->setFormTypeOptions(['class' => Mandate::class])->setColumns(4);
        yield TextField::new('label', 'Objet')->setColumns(5);
        yield DateField::new('date', 'Date')->setColumns(3);
        yield AssociationField::new('unit', 'Bien')->setFormType(EntityType::class)->setFormTypeOptions(['class' => Unit::class, 'required' => false])->setRequired(false)->setColumns(4)->hideOnIndex();
        yield AssociationField::new('contractor', 'Artisan')->setFormType(EntityType::class)->setFormTypeOptions(['class' => Contractor::class, 'required' => false])->setRequired(false)->setColumns(4)->hideOnIndex();
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
        if (null !== $mandate = $this->expenseEntityManager?->getRepository(Mandate::class)->findOneBy([], ['id' => 'ASC'])) {
            $expense->setMandate($mandate);
        }

        return $expense;
    }

    public function persistEntity(EntityManagerInterface $entityManager, object $entity): void
    {
        parent::persistEntity($entityManager, $entity);
        if ($this->management?->isActive()) {
            $this->management->postExpense($entity);
        }
    }

    public function updateEntity(EntityManagerInterface $entityManager, object $entity): void
    {
        parent::updateEntity($entityManager, $entity);
        if ($this->management?->isActive()) {
            $this->management->postExpense($entity);
        }
    }
}
