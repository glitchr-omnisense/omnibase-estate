<?php

namespace Base\Estate\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Estate\Entity\Organisation;
use Base\Field\DateField;
use Base\Field\DateTimeField;
use Base\Field\EmailField;
use Base\Field\IdField;
use Base\Field\IntegerField;
use Base\Field\TextField;

/**
 * The companies: their identity, their address, the IBAN rents are paid to, their books.
 * With glitchr/omnistate, the identity is read from the registers by the SIREN.
 */
class OrganisationCrudController extends AbstractEstateCrudController
{
    public static function getEntityFqcn(): string { return Organisation::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-building-columns'; }

    public function configureActions(Actions $actions): Actions
    {
        $actions = parent::configureActions($actions);
        if (!class_exists(\Omnistate\Omnistate::class)) {
            return $actions;
        }
        $refresh = Action::new('estateCompanyRefresh', 'Lire les registres', 'fa-solid fa-landmark-flag')
            ->linkToRoute('estate_admin_company_refresh', fn (Organisation $o) => ['id' => $o->getId(), '_token' => $this->actionToken('estate_company_refresh', $o->getId())])
            ->displayIf(fn (Organisation $o) => null !== $o->getSiren());

        return $actions->add(Action::INDEX, $refresh)->add(Action::DETAIL, $refresh);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('name', 'Nom sur le site')->setColumns(6);
        yield TextField::new('slug', 'Identifiant')->setColumns(3)->hideOnIndex();
        yield TextField::new('siren', 'SIREN')->setColumns(3)->setHelp('Le reste de l\'identité se lit dans les registres (Sirene, VIES) : « Lire les registres ».');
        yield TextField::new('legalName', 'Dénomination')->setColumns(6)->hideOnIndex();
        yield TextField::new('legalForm', 'Forme juridique')->setColumns(3)->hideOnIndex();
        yield TextField::new('vatNumber', 'N° de TVA')->setColumns(3)->hideOnIndex()->setHelp('Vide : non assujettie (inconnue de VIES).');
        yield DateTimeField::new('registryCheckedAt', 'Registres lus le')->onlyOnDetail();
        yield DateField::new('foundedOn', 'Créée le')->setColumns(3)->hideOnIndex();
        yield TextField::new('address', 'Adresse')->setColumns(6)->hideOnIndex();
        yield TextField::new('postcode', 'Code postal')->setColumns(2)->hideOnIndex();
        yield TextField::new('city', 'Ville')->setColumns(4);
        yield EmailField::new('email', 'E-mail')->setColumns(4);
        yield TextField::new('phone', 'Téléphone')->setColumns(4)->hideOnIndex();
        yield TextField::new('iban', 'IBAN des loyers')->setColumns(4)->hideOnIndex()->setHelp('Imprimé sur les avis d\'échéance pour les virements.');
        yield IntegerField::new('bookId', 'Comptabilité (n° du livre)')->setColumns(4)->hideOnIndex()->setHelp('Le livre comptable (omnibase/ledger) où loyers et travaux sont passés.');
    }
}
