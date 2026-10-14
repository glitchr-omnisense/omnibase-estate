<?php

namespace Base\Estate\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Estate\Entity\Contractor;
use Base\Field\AssociationField;
use Base\Field\EmailField;
use Base\Field\TextField;

class ContractorCrudController extends AbstractEstateCrudController
{
    public static function getEntityFqcn(): string { return Contractor::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-helmet-safety'; }

    public function configureActions(Actions $actions): Actions
    {
        $invite = Action::new('estateInvite', 'Inviter', 'fa-solid fa-paper-plane')
            ->linkToRoute('estate_admin_invite', fn (Contractor $c) => ['kind' => 'contractor', 'id' => $c->getId(), '_token' => $this->actionToken('estate_invite_contractor', $c->getId())])
            ->displayIf(fn (Contractor $c) => null === $c->getUser());

        return parent::configureActions($actions)->add(Action::INDEX, $invite)->add(Action::DETAIL, $invite);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('organisation', 'Société')->setColumns(4)->hideOnIndex();
        yield TextField::new('company', 'Entreprise')->setColumns(4);
        yield TextField::new('trade', 'Métier')->setColumns(4);
        yield TextField::new('contactName', 'Contact')->setRequired(false)->setColumns(4);
        yield EmailField::new('email', 'E-mail')->setColumns(4);
        yield TextField::new('phone', 'Téléphone')->setRequired(false)->setColumns(4);
        yield TextField::new('siret', 'SIRET')->setRequired(false)->setColumns(4)->hideOnIndex();
        yield TextField::new('iban', 'IBAN')->setRequired(false)->setColumns(4)->hideOnIndex();
        yield AssociationField::new('user', 'Compte')->setColumns(4)->setRequired(false)->hideOnIndex()->hideOnForm();
    }
}
