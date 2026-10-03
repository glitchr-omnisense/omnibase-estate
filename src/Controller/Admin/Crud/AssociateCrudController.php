<?php

namespace Base\Estate\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Estate\Entity\Associate;
use Base\Estate\Enum\AssociateQuality;
use Base\Field\AssociationField;
use Base\Field\BooleanField;
use Base\Field\DateField;
use Base\Field\EmailField;
use Base\Field\ImageField;
use Base\Field\IntegerField;
use Base\Field\TextareaField;
use Base\Field\TextField;

/** The associates: their shares, and the access to their space, by invitation. */
class AssociateCrudController extends AbstractEstateCrudController
{
    public static function getEntityFqcn(): string { return Associate::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-people-group'; }

    public function configureActions(Actions $actions): Actions
    {
        $invite = Action::new('estateInvite', 'Inviter', 'fa-solid fa-paper-plane')
            ->linkToRoute('estate_admin_invite', fn (Associate $a) => ['kind' => 'associate', 'id' => $a->getId(), '_token' => $this->actionToken('estate_invite_associate', $a->getId())])
            ->displayIf(fn (Associate $a) => null === $a->getUser());

        return parent::configureActions($actions)->add(Action::INDEX, $invite)->add(Action::DETAIL, $invite);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('organisation', 'Société')->setColumns(4);
        yield TextField::new('firstName', 'Prénom')->setColumns(4);
        yield TextField::new('lastName', 'Nom')->setColumns(4);
        yield EmailField::new('email', 'E-mail')->setColumns(4);
        yield TextField::new('phone', 'Téléphone')->setColumns(4)->hideOnIndex();
        yield IntegerField::new('shares', 'Parts')->setColumns(2);
        yield $this->enum('quality', 'Qualité', AssociateQuality::class, fn (AssociateQuality $q) => AssociateQuality::MANAGER === $q ? 'Gérant·e' : 'Associé·e')->setColumns(2);
        yield DateField::new('since', 'Depuis')->setColumns(3)->hideOnIndex();
        yield BooleanField::new('public', 'Sur le site')->setColumns(3)->setHelp('Montré·e sur la page « La société » : seulement avec son accord.');
        yield ImageField::new('portrait', 'Portrait')->setColumns(4)->hideOnIndex()->setRequired(false);
        yield TextareaField::new('bio', 'Quelques mots (site public)')->setColumns(8)->hideOnIndex();
        yield AssociationField::new('user', 'Compte')->setColumns(4)->setRequired(false)->hideOnIndex()->hideOnForm();
    }
}
