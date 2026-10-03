<?php

namespace Base\Estate\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Estate\Entity\Invitation;
use Base\Field\DateTimeField;
use Base\Field\TextField;

class InvitationCrudController extends AbstractEstateCrudController
{
    public static function getEntityFqcn(): string { return Invitation::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-envelope-open-text'; }

    public function configureActions(Actions $actions): Actions
    {
        return parent::configureActions($actions)->disable(Action::NEW, Action::EDIT);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('email', 'E-mail');
        yield TextField::new('name', 'Nom');
        yield TextField::new('kind', 'Pour')->formatValue(fn ($v) => $v instanceof \BackedEnum ? ['member' => 'Gestion', 'associate' => 'Associé·e', 'tenant' => 'Locataire', 'contractor' => 'Artisan'][$v->value] : $v);
        yield DateTimeField::new('createdAt', 'Envoyée le');
        yield DateTimeField::new('expiresAt', 'Valable jusqu\'au');
        yield DateTimeField::new('acceptedAt', 'Acceptée le');
    }
}
