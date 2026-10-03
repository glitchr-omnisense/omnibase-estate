<?php

namespace Base\Estate\Controller\Admin\Crud;

use Base\Estate\Entity\Deadline;
use Base\Field\AssociationField;
use Base\Field\BooleanField;
use Base\Field\DateField;
use Base\Field\TextareaField;
use Base\Field\TextField;

class DeadlineCrudController extends AbstractEstateCrudController
{
    public static function getEntityFqcn(): string { return Deadline::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-calendar-day'; }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('organisation', 'Société')->setColumns(4);
        yield TextField::new('title', 'Échéance')->setColumns(4);
        yield DateField::new('dueOn', 'Date')->setColumns(2);
        yield BooleanField::new('yearly', 'Chaque année')->setColumns(2);
        yield TextareaField::new('notes', 'Notes')->setColumns(12)->hideOnIndex();
    }
}
