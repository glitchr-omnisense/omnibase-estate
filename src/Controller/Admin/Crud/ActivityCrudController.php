<?php

namespace Base\Estate\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Estate\Entity\Activity;
use Base\Field\AssociationField;
use Base\Field\DateTimeField;
use Base\Field\TextField;

class ActivityCrudController extends AbstractEstateCrudController
{
    public static function getEntityFqcn(): string { return Activity::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-timeline'; }

    public function configureActions(Actions $actions): Actions
    {
        return parent::configureActions($actions)->disable(Action::NEW, Action::EDIT);
    }

    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('happenedAt', 'Quand');
        yield TextField::new('message', 'Quoi');
        yield AssociationField::new('unit', 'Bien');
        yield $this->euros('amount', 'Montant');
    }
}
