<?php

namespace Base\Estate\Controller\Admin\Crud;

use Base\Estate\Entity\UnitPhoto;
use Base\Field\AssociationField;
use Base\Field\ImageField;
use Base\Field\IntegerField;
use Base\Field\TextField;

class UnitPhotoCrudController extends AbstractEstateCrudController
{
    public static function getEntityFqcn(): string { return UnitPhoto::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-camera'; }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('unit', 'Bien')->setFormType(\Symfony\Bridge\Doctrine\Form\Type\EntityType::class)->setFormTypeOptions(['class' => \Base\Estate\Entity\Unit::class])->setColumns(4);
        yield ImageField::new('image', 'Photo')->setColumns(4)->setRequired(false);
        yield TextField::new('caption', 'Légende')->setColumns(4);
        yield IntegerField::new('position', 'Ordre')->setColumns(2);
    }
}
