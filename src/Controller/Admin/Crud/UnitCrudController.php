<?php

namespace Base\Estate\Controller\Admin\Crud;

use Base\Estate\Entity\Unit;
use Base\Estate\Enum\UnitKind;
use Base\Field\AssociationField;
use Base\Field\BooleanField;
use Base\Field\DateField;
use Base\Field\IntegerField;
use Base\Field\NumberField;
use Base\Field\SelectField;
use Base\Field\TextareaField;
use Base\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

/** The homes: what they are, what they cost, whether they are on the public site and when they are free. */
class UnitCrudController extends AbstractEstateCrudController
{
    public const KINDS = ['house' => 'Maison', 'apartment' => 'Appartement', 'studio' => 'Studio', 'parking' => 'Parking', 'commercial' => 'Local commercial'];

    public static function getEntityFqcn(): string { return Unit::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-house'; }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('organisation', 'Société')->setColumns(4)->hideOnIndex();
        yield TextField::new('name', 'Nom')->setColumns(4);
        yield TextField::new('slug', 'Adresse web')->setColumns(4)->hideOnIndex()->setHelp('/biens/<adresse-web>');
        yield $this->enum('kind', 'Type', UnitKind::class, fn (UnitKind $k) => self::KINDS[$k->value])->setColumns(3);
        yield AssociationField::new('building', 'Immeuble')->setColumns(3)->setRequired(false)->hideOnIndex();
        yield TextField::new('district', 'Quartier')->setRequired(false)->setColumns(3);
        yield TextField::new('door', 'Porte / lot')->setRequired(false)->setColumns(3)->hideOnIndex();
        yield TextField::new('address', 'Adresse (sans immeuble)')->setRequired(false)->setColumns(6)->hideOnIndex();
        yield TextField::new('postcode', 'Code postal')->setRequired(false)->setColumns(2)->hideOnIndex();
        yield TextField::new('city', 'Ville')->setRequired(false)->setColumns(4)->hideOnIndex();
        yield NumberField::new('surface', 'Surface (m²)')->setRequired(false)->setColumns(2);
        yield IntegerField::new('rooms', 'Pièces')->setRequired(false)->setColumns(2);
        yield IntegerField::new('bedrooms', 'Chambres')->setRequired(false)->setColumns(2)->hideOnIndex();
        yield IntegerField::new('floor', 'Étage')->setRequired(false)->setColumns(2)->hideOnIndex();
        yield TextField::new('heating', 'Chauffage')->setRequired(false)->setColumns(4)->hideOnIndex();
        yield TextField::new('energyClass', 'DPE (A-G)')->setRequired(false)->setColumns(2)->hideOnIndex();
        yield IntegerField::new('energyValue', 'kWh/m²/an')->setRequired(false)->setColumns(2)->hideOnIndex();
        yield TextField::new('climateClass', 'GES (A-G)')->setRequired(false)->setColumns(2)->hideOnIndex();
        yield $this->euros('rent', 'Loyer hors charges')->setColumns(3);
        yield $this->euros('charges', 'Provision sur charges')->setColumns(3)->hideOnIndex();
        yield $this->euros('deposit', 'Dépôt de garantie')->setColumns(3)->hideOnIndex();
        yield BooleanField::new('furnished', 'Meublé')->setColumns(3)->hideOnIndex();
        yield TextareaField::new('description', 'Description (site public)')->setRequired(false)->setColumns(12)->hideOnIndex();
        yield TextField::new('features', 'Atouts (séparés par des virgules)')->setRequired(false)->setColumns(12)->hideOnIndex()
            ->formatValue(fn ($value) => \is_array($value) ? implode(', ', $value) : $value);
        yield BooleanField::new('published', 'Sur le site')->setColumns(3);
        yield DateField::new('availableFrom', 'Libre le')->setRequired(false)->setColumns(3)->setHelp('Vide tant qu\'il est loué.');
        yield TextField::new('facade', 'Couleur de façade')->setColumns(3)->hideOnIndex()
            ->setFormType(ChoiceType::class)->setFormTypeOptions(['choices' => ['Ocre' => 'ochre', 'Rose' => 'rose', 'Sauge' => 'sage', 'Ciel' => 'sky', 'Brique' => 'brick', 'Moutarde' => 'mustard']]);
        yield IntegerField::new('position', 'Ordre')->setColumns(3)->hideOnIndex();
    }
}
