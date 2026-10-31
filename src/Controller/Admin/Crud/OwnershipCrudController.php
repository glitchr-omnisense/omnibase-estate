<?php

namespace Base\Estate\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\Ownership;
use Base\Estate\Entity\Unit;
use Base\Estate\Service\Ownerships;
use Base\Field\AssociationField;
use Base\Field\DateTimeField;
use Base\Field\TextareaField;
use Base\Field\TextField;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * The shared ownerships (indivisions): their homes, their co-owners' shares
 * - checked to add up to one on every date they change -, their statements
 * and their cloud folder. Saved, the folder is made and shared with the
 * co-owners who have an account (with omnibase/drive).
 */
class OwnershipCrudController extends AbstractEstateCrudController
{
    private ?Ownerships $ownerships = null;

    #[Required]
    public function setOwnerships(Ownerships $ownerships): void
    {
        $this->ownerships = $ownerships;
    }

    public static function getEntityFqcn(): string { return Ownership::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-people-roof'; }

    public function configureActions(Actions $actions): Actions
    {
        $statements = Action::new('estateOwnershipStatements', 'Relevés', 'fa-solid fa-file-invoice')
            ->linkToRoute('estate_admin_ownership_statements', fn (Ownership $o) => ['ownership' => $o->getId()]);
        $shares = Action::new('estateOwnershipShares', 'Quotes-parts', 'fa-solid fa-chart-pie')
            ->linkToRoute('admin_crud_ownership_shares_index', fn () => []);
        $actions = parent::configureActions($actions)
            ->add(Action::INDEX, $statements)->add(Action::DETAIL, $statements)->add(Action::DETAIL, $shares);
        if ($this->ownerships?->hasCloud()) {
            $folder = Action::new('estateOwnershipFolder', 'Dossier cloud', 'fa-solid fa-cloud')
                ->linkToRoute('estate_admin_ownership_folder', fn (Ownership $o) => ['id' => $o->getId(), '_token' => $this->actionToken('estate_ownership_folder', $o->getId())]);
            $actions->add(Action::DETAIL, $folder);
        }

        return $actions;
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Nom')->setColumns(5);
        yield AssociationField::new('organisation', 'Gérée par')->setFormType(EntityType::class)->setFormTypeOptions(['class' => Organisation::class])->setColumns(3)->setHelp('La société qui la gère : ses comptes portent l\'indivision.');
        yield AssociationField::new('units', 'Biens')->setFormType(EntityType::class)->setFormTypeOptions(['class' => Unit::class, 'multiple' => true, 'by_reference' => false])->setRequired(false)->setColumns(4);
        yield TextareaField::new('description', 'Quelques mots')->setRequired(false)->setColumns(12)->hideOnIndex();
        yield TextField::new('sharesSummary', 'Quotes-parts')->hideOnForm();
        yield TextField::new('folder', 'Dossier cloud')->hideOnForm()->hideOnIndex();
        yield DateTimeField::new('createdAt', 'Créée le')->hideOnForm()->hideOnIndex();
    }

    public function persistEntity(EntityManagerInterface $entityManager, object $entity): void
    {
        parent::persistEntity($entityManager, $entity);
        $this->ownerships?->syncFolder($entity);
    }

    public function updateEntity(EntityManagerInterface $entityManager, object $entity): void
    {
        parent::updateEntity($entityManager, $entity);
        $this->ownerships?->syncFolder($entity);
    }
}
