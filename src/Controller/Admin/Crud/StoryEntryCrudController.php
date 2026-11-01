<?php

namespace Base\Estate\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Config\Crud;
use Base\Estate\Entity\StoryEntry;
use Base\Estate\Entity\Unit;
use Base\Estate\Enum\StoryState;
use Base\Field\AssociationField;
use Base\Field\BooleanField;
use Base\Field\DateField;
use Base\Field\TextareaField;
use Base\Field\TextField;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;

/**
 * The homes' stories: each entry's date, title and text, its state (works under
 * way or done), dates and investment's amount (shown only when ticked) here; its photos
 * (before/after, "OK to publish"), publishing and the images for the networks
 * on its own page (« Photos et réseaux »). Published only from there, once
 * nothing keeps it from the public.
 */
class StoryEntryCrudController extends AbstractEstateCrudController
{
    public static function getEntityFqcn(): string { return StoryEntry::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-book-open'; }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setDefaultSort(['happenedOn' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $page = Action::new('estateStory', 'Photos et réseaux', 'fa-solid fa-images')
            ->linkToRoute('estate_admin_story', fn (StoryEntry $e) => ['id' => $e->getId()]);

        return parent::configureActions($actions)->add(Action::INDEX, $page)->add(Action::DETAIL, $page);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('unit', 'Bien')->setFormType(EntityType::class)->setFormTypeOptions(['class' => Unit::class])->setColumns(4);
        yield DateField::new('happenedOn', 'Date')->setColumns(3);
        yield TextField::new('title', 'Titre')->setColumns(5)->setHelp('Ce qui s\'est passé : « Cuisine refaite », « Façade ravalée », « Construit en 1898 ».');
        yield TextareaField::new('text', 'Texte')->setRequired(false)->setColumns(12)->hideOnIndex()
            ->setHelp('Public une fois l\'entrée publiée : ce qui a été fait, pourquoi. Aucun nom de locataire.');
        yield $this->enum('state', 'État', StoryState::class, fn (StoryState $s) => StoryState::IN_PROGRESS === $s ? 'Travaux en cours' : 'Terminé')->setColumns(3)
            ->setHelp('Les interventions liées le suggèrent ; c\'est vous qui décidez.');
        yield DateField::new('startedOn', 'Commencés le')->setRequired(false)->setColumns(3)->hideOnIndex();
        yield DateField::new('plannedEndOn', 'Fin prévue')->setRequired(false)->setColumns(3)->hideOnIndex();
        yield $this->euros('amount', 'Montant investi')->setRequired(false)->setColumns(3)->hideOnIndex();
        yield BooleanField::new('showAmount', 'Montrer le montant')->setColumns(3)->hideOnIndex()->setHelp('Masqué par défaut.');
        yield BooleanField::new('published', 'Publiée')->hideOnForm()->renderAsSwitch(false);
    }
}
