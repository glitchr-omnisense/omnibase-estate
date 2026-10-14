<?php

namespace Base\Estate\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Estate\Entity\Member;
use Base\Field\AssociationField;
use Base\Field\EmailField;
use Base\Field\TextField;

/** The management team: who runs the back office. Invited by e-mail. */
class MemberCrudController extends AbstractEstateCrudController
{
    public static function getEntityFqcn(): string { return Member::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-user-tie'; }

    public function configureActions(Actions $actions): Actions
    {
        $invite = Action::new('estateInvite', 'Inviter', 'fa-solid fa-paper-plane')
            ->linkToRoute('estate_admin_invite', fn (Member $m) => ['kind' => 'member', 'id' => $m->getId(), '_token' => $this->actionToken('estate_invite_member', $m->getId())])
            ->displayIf(fn (Member $m) => null === $m->getUser());

        return parent::configureActions($actions)->add(Action::INDEX, $invite)->add(Action::DETAIL, $invite);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('organisation', 'Société')->setColumns(4);
        yield TextField::new('name', 'Nom')->setColumns(4);
        yield EmailField::new('email', 'E-mail')->setColumns(4);
        yield TextField::new('title', 'Fonction')->setRequired(false)->setColumns(4);
        yield AssociationField::new('user', 'Compte')->setColumns(4)->setRequired(false)->setHelp('Rempli quand l\'invitation est acceptée.')->hideOnForm();
    }
}
