<?php

namespace Base\Estate\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Estate\Entity\LeaseParty;
use Base\Estate\Enum\PartyRole;
use Base\Field\AssociationField;
use Base\Field\EmailField;
use Base\Field\TextField;

/** The people on the leases; a tenant gets their space by invitation. */
class LeasePartyCrudController extends AbstractEstateCrudController
{
    public static function getEntityFqcn(): string { return LeaseParty::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-user'; }

    public function configureActions(Actions $actions): Actions
    {
        $invite = Action::new('estateInvite', 'Inviter', 'fa-solid fa-paper-plane')
            ->linkToRoute('estate_admin_invite', fn (LeaseParty $p) => ['kind' => 'tenant', 'id' => $p->getId(), '_token' => $this->actionToken('estate_invite_tenant', $p->getId())])
            ->displayIf(fn (LeaseParty $p) => null === $p->getUser() && PartyRole::GUARANTOR !== $p->getRole());

        return parent::configureActions($actions)->add(Action::INDEX, $invite)->add(Action::DETAIL, $invite);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('lease', 'Bail')->setColumns(4);
        yield $this->enum('role', 'Rôle', PartyRole::class, fn (PartyRole $r) => ['tenant' => 'Locataire', 'cotenant' => 'Colocataire', 'guarantor' => 'Garant'][$r->value])->setColumns(2);
        yield TextField::new('firstName', 'Prénom')->setColumns(3);
        yield TextField::new('lastName', 'Nom')->setColumns(3);
        yield EmailField::new('email', 'E-mail')->setColumns(4);
        yield TextField::new('phone', 'Téléphone')->setColumns(4)->hideOnIndex();
        yield TextField::new('iban', 'IBAN (ses virements)')->setColumns(4)->hideOnIndex()->setHelp('Pour reconnaître ses virements au rapprochement bancaire.');
        yield AssociationField::new('user', 'Compte')->setColumns(4)->setRequired(false)->hideOnIndex()->hideOnForm();
    }
}
