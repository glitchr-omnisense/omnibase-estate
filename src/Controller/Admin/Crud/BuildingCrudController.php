<?php

namespace Base\Estate\Controller\Admin\Crud;

use Base\Estate\Entity\Building;
use Base\Field\AssociationField;
use Base\Field\IntegerField;
use Base\Field\TextareaField;
use Base\Field\TextField;

class BuildingCrudController extends AbstractEstateCrudController
{
    public static function getEntityFqcn(): string { return Building::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-building'; }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('organisation', 'Société')->setColumns(4);
        yield TextField::new('name', 'Nom')->setColumns(4);
        yield IntegerField::new('builtIn', 'Construit en')->setColumns(4)->hideOnIndex();
        yield TextField::new('address', 'Adresse')->setColumns(6);
        yield TextField::new('postcode', 'Code postal')->setColumns(2);
        yield TextField::new('city', 'Ville')->setColumns(4);
        yield TextareaField::new('access', 'Accès (digicode, clés)')->setColumns(12)->hideOnIndex()->setHelp('Donné aux artisans envoyés sur place, jamais sur le site public.');
    }
}
