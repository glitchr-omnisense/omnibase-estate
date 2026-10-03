<?php

namespace Base\Estate\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Estate\Entity\Notice;
use Base\Field\AssociationField;
use Base\Field\DateTimeField;
use Base\Field\TextareaField;
use Base\Field\TextField;

class NoticeCrudController extends AbstractEstateCrudController
{
    public static function getEntityFqcn(): string { return Notice::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-bullhorn'; }

    public function configureActions(Actions $actions): Actions
    {
        $send = Action::new('estateSend', 'Envoyer aux locataires', 'fa-solid fa-paper-plane')
            ->linkToRoute('estate_admin_notice_send', fn (Notice $n) => ['id' => $n->getId(), '_token' => $this->actionToken('estate_notice_send', $n->getId())])
            ->displayIf(fn (Notice $n) => null === $n->getSentAt());

        return parent::configureActions($actions)->add(Action::INDEX, $send)->add(Action::DETAIL, $send);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('organisation', 'Société')->setColumns(4);
        yield AssociationField::new('building', 'Immeuble (vide : tous)')->setColumns(4)->setRequired(false);
        yield TextField::new('title', 'Titre')->setColumns(12);
        yield TextareaField::new('body', 'Message')->setColumns(12)->hideOnIndex();
        yield DateTimeField::new('sentAt', 'Envoyé le')->hideOnForm();
    }
}
