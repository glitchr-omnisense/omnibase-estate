<?php

namespace Base\Estate\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Config\Crud;
use Base\Estate\Entity\Buyer;
use Base\Estate\Enum\Financing;
use Base\Estate\Enum\UnitKind;
use Base\Field\AssociationField;
use Base\Field\EmailField;
use Base\Field\IntegerField;
use Base\Field\TextareaField;
use Base\Field\TextField;

/**
 * The buyers: how to reach them, their account - it opens their space -,
 * what they look for (budget, kind, surface, rooms, area) and how they pay.
 * « Biens correspondants » matches them against the sale mandates.
 */
class BuyerCrudController extends AbstractEstateCrudController
{

    public static function getEntityFqcn(): string { return Buyer::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-user-check'; }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $matches = Action::new('estateBuyerMatches', 'Biens correspondants', 'fa-solid fa-magnifying-glass-location')
            ->linkToRoute('estate_admin_transaction_matches', fn (Buyer $b) => ['id' => $b->getId()]);

        return parent::configureActions($actions)->add(Action::INDEX, $matches)->add(Action::DETAIL, $matches);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Nom')->setColumns(5);
        yield EmailField::new('email', 'E-mail')->setRequired(false)->setColumns(4);
        yield TextField::new('phone', 'Téléphone')->setRequired(false)->setColumns(3)->hideOnIndex();
        yield AssociationField::new('user', 'Compte')->setRequired(false)->setColumns(4)->hideOnIndex()->setHelp('Son compte ouvre son espace acquéreur : ses offres et, acceptée, les étapes de la vente.');
        yield $this->euros('budget', 'Budget')->setRequired(false)->setColumns(4);
        yield $this->enum('kind', 'Type cherché', UnitKind::class, fn (UnitKind $k) => UnitCrudController::KINDS[$k->value])->setFormTypeOptions(['class' => UnitKind::class, 'choice_label' => fn (UnitKind $k) => UnitCrudController::KINDS[$k->value], 'required' => false, 'placeholder' => 'Indifférent'])->setRequired(false)->setColumns(4);
        yield IntegerField::new('minSurface', 'Surface minimale (m²)')->setRequired(false)->setColumns(4)->hideOnIndex();
        yield IntegerField::new('minRooms', 'Pièces au moins')->setRequired(false)->setColumns(4)->hideOnIndex();
        yield TextField::new('area', 'Secteur')->setRequired(false)->setColumns(8)->setHelp('Villes, codes postaux ou quartiers, séparés par des virgules.');
        yield $this->enum('financing', 'Financement', Financing::class, fn (Financing $f) => $f->label())->setColumns(4)->hideOnIndex();
        yield $this->euros('loanAmount', 'Montant du prêt')->setRequired(false)->setColumns(4)->hideOnIndex();
        yield TextareaField::new('notes', 'Notes')->setRequired(false)->setColumns(12)->hideOnIndex();
    }
}
