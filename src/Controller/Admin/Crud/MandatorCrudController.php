<?php

namespace Base\Estate\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Config\Crud;
use Base\Estate\Entity\Mandator;
use Base\Estate\Service\Management;
use Base\Estate\Service\Transactions;
use Base\Field\AssociationField;
use Base\Field\BooleanField;
use Base\Field\EmailField;
use Base\Field\TextareaField;
use Base\Field\TextField;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * The owners who give the agency a mandate (mandants): to manage their
 * homes (management) or to sell one (transaction, as sellers). A person or
 * a company, how to reach them, the IBAN their payouts go to, their
 * account - it opens their space. Saved under management, their cloud
 * folder is made and shared with them read-only (with omnibase/drive).
 */
class MandatorCrudController extends AbstractEstateCrudController
{
    private ?Management $management = null;
    private ?Transactions $transactions = null;

    #[Required]
    public function setManagement(Management $management, Transactions $transactions): void
    {
        $this->management = $management;
        $this->transactions = $transactions;
    }

    public static function getEntityFqcn(): string { return Mandator::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-user-tie'; }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setDefaultSort(['name' => 'ASC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = parent::configureActions($actions);
        if ($this->management?->isActive()) {
            $actions->add(Action::DETAIL, Action::new('estateMandatorMandates', 'Mandats de gestion', 'fa-solid fa-file-signature')->linkToRoute('admin_crud_mandates_index', fn () => []));
        }
        if ($this->transactions?->isActive()) {
            $actions->add(Action::DETAIL, Action::new('estateMandatorSaleMandates', 'Mandats de vente', 'fa-solid fa-sign-hanging')->linkToRoute('admin_crud_sale_mandates_index', fn () => []));
        }
        if ($this->management?->isActive() && $this->management->hasCloud()) {
            $folder = Action::new('estateMandatorFolder', 'Dossier cloud', 'fa-solid fa-cloud')
                ->linkToRoute('estate_admin_management_folder', fn (Mandator $m) => ['id' => $m->getId(), '_token' => $this->actionToken('estate_management_folder', $m->getId())]);
            $actions->add(Action::DETAIL, $folder);
        }

        return $actions;
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Nom')->setColumns(5);
        yield BooleanField::new('company', 'Une société')->setColumns(3)->hideOnIndex();
        yield TextField::new('siren', 'SIREN')->setRequired(false)->setColumns(4)->hideOnIndex();
        yield EmailField::new('email', 'E-mail')->setRequired(false)->setColumns(4)->setHelp('Pour le joindre ; les comptes rendus de gérance partent à cette adresse.');
        yield TextField::new('phone', 'Téléphone')->setRequired(false)->setColumns(4)->hideOnIndex();
        yield AssociationField::new('user', 'Compte')->setRequired(false)->setColumns(4)->hideOnIndex()->setHelp('Son compte ouvre son espace (gérance, vente) et ses dossiers cloud.');
        yield TextField::new('address', 'Adresse')->setRequired(false)->setColumns(6)->hideOnIndex();
        yield TextField::new('postcode', 'Code postal')->setRequired(false)->setColumns(2)->hideOnIndex();
        yield TextField::new('city', 'Ville')->setRequired(false)->setColumns(4)->hideOnIndex();
        yield TextField::new('iban', 'IBAN')->setRequired(false)->setColumns(6)->setHelp('Ses reversements partent vers ce compte (fichier SEPA).');
        yield TextField::new('bic', 'BIC')->setRequired(false)->setColumns(3)->hideOnIndex()->setHelp('Facultatif en SEPA.');
        yield TextareaField::new('notes', 'Notes')->setRequired(false)->setColumns(12)->hideOnIndex();
        yield TextField::new('folder', 'Dossier cloud')->hideOnForm()->hideOnIndex();
    }

    public function persistEntity(EntityManagerInterface $entityManager, object $entity): void
    {
        parent::persistEntity($entityManager, $entity);
        if ($this->management?->isActive()) {
            $this->management->syncFolder($entity);
        }
    }

    public function updateEntity(EntityManagerInterface $entityManager, object $entity): void
    {
        parent::updateEntity($entityManager, $entity);
        if ($this->management?->isActive()) {
            $this->management->syncFolder($entity);
        }
    }
}
