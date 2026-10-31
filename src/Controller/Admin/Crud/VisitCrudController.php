<?php

namespace Base\Estate\Controller\Admin\Crud;

use Base\Admin\Config\Crud;
use Base\Estate\Entity\Buyer;
use Base\Estate\Entity\SaleMandate;
use Base\Estate\Entity\Visit;
use Base\Field\AssociationField;
use Base\Field\DateTimeField;
use Base\Field\TextareaField;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;

/**
 * The visits: a buyer, a mandate, when, and a few words after. The seller
 * sees the date and those words in their space, never who visited.
 * ?mandate=<id> or ?buyer=<id> opens a new one on them.
 */
class VisitCrudController extends AbstractEstateCrudController
{
    public static function getEntityFqcn(): string { return Visit::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-door-open'; }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setDefaultSort(['visitedAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('mandate', 'Mandat')->setFormType(EntityType::class)->setFormTypeOptions(['class' => SaleMandate::class])->setColumns(5);
        yield AssociationField::new('buyer', 'Acquéreur')->setFormType(EntityType::class)->setFormTypeOptions(['class' => Buyer::class])->setColumns(4);
        yield DateTimeField::new('visitedAt', 'Le')->setColumns(3);
        yield TextareaField::new('feedback', 'Retour de visite')->setRequired(false)->setColumns(12)->setHelp('Quelques mots, lus par le vendeur dans son espace (sans le nom de l\'acquéreur).');
    }

    public function createEntity(string $entityFqcn): Visit
    {
        return $this->prefillSale(new Visit());
    }
}
