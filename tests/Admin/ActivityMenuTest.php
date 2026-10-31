<?php

namespace Tests\Base\Estate\Admin;

use Base\Admin\Config\Menu\MenuItem as Item;
use Base\Admin\Config\MenuItem;
use Base\Estate\Admin\ActivityMenu;
use Base\Estate\Entity\Associate;
use Base\Estate\Entity\Contractor;
use Base\Estate\Entity\Lease;
use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\Ownership;
use Base\Estate\Entity\OwnershipCall;
use Base\Estate\Entity\OwnershipInvoice;
use Base\Estate\Entity\OwnershipShare;
use Base\Estate\Entity\RentCall;
use Base\Estate\Entity\Unit;
use Base\Estate\Entity\WorkOrder;
use Base\Estate\Service\Activities;
use PHPUnit\Framework\TestCase;

/**
 * A dashboard's items through ActivityMenu::filter(), as meyers yields
 * them: the cards and the screens of an activity the site does not run are
 * left out, a section left empty too, and what stays keeps its label, its
 * place and its key (the layout the team arranged is stored by key).
 */
final class ActivityMenuTest extends TestCase
{
    /** @return list<Item> */
    private static function items(): array
    {
        return [
            MenuItem::block('estate_occupancy', 'estate_widget.occupancy', 'fa-solid fa-house-circle-check')->setSize(1),
            MenuItem::block('ledger_bank_balance', 'ledger_widget.balance', 'fa-solid fa-sack-dollar')->setSize(1),
            MenuItem::block('estate_rents', 'estate_widget.rents', 'fa-solid fa-chart-column')->setSize(2),
            MenuItem::section('Gestion locative', 'fa-solid fa-house')->setSubItems([
                MenuItem::linkToCrud(Unit::class, 'Biens', 'fa-solid fa-house'),
                MenuItem::linkToCrud(Lease::class, 'Baux', 'fa-solid fa-file-signature'),
                MenuItem::linkToRoute('estate_admin_messages', [], 'Messages des locataires', 'fa-solid fa-comments'),
            ]),
            MenuItem::section('Loyers', 'fa-solid fa-file-invoice-dollar')->setSubItems([
                MenuItem::linkToCrud(RentCall::class, 'Appels de loyer', 'fa-solid fa-file-invoice-dollar'),
            ]),
            MenuItem::section('Travaux', 'fa-solid fa-screwdriver-wrench')->setSubItems([
                MenuItem::linkToCrud(WorkOrder::class, 'Interventions', 'fa-solid fa-screwdriver-wrench'),
                MenuItem::linkToCrud(Contractor::class, 'Artisans', 'fa-solid fa-helmet-safety'),
            ]),
            MenuItem::section('La société', 'fa-solid fa-landmark')->setSubItems([
                MenuItem::linkToCrud(Organisation::class, 'Société', 'fa-solid fa-landmark'),
                MenuItem::linkToCrud(Associate::class, 'Associés', 'fa-solid fa-people-group'),
            ]),
            MenuItem::section('Indivisions', 'fa-solid fa-people-roof')->setSubItems([
                MenuItem::linkToCrud(Ownership::class, 'Indivisions', 'fa-solid fa-people-roof'),
                MenuItem::linkToCrud(OwnershipShare::class, 'Quotes-parts', 'fa-solid fa-chart-pie'),
                MenuItem::linkToCrud(OwnershipInvoice::class, 'Factures partagées', 'fa-solid fa-file-invoice'),
                MenuItem::linkToCrud(OwnershipCall::class, 'Appels de fonds', 'fa-solid fa-hand-holding-dollar'),
                MenuItem::linkToRoute('estate_admin_ownership_statements', [], 'Relevés des indivisaires', 'fa-solid fa-file-invoice'),
            ]),
            MenuItem::block('estate_ownership', 'estate_widget.ownership', 'fa-solid fa-people-roof')->setSize(2),
            MenuItem::linkToRoute('admin_settings', [], 'Réglages', 'fa-solid fa-sliders'),
        ];
    }

    /** @return list<string|array{0: string, 1: list<string>}> each item's label, a section's with its items' */
    private static function labels(iterable $items): array
    {
        $labels = [];
        foreach ($items as $item) {
            $labels[] = [] === $item->getSubItems() ? (string) $item->getLabel() : [(string) $item->getLabel(), array_map(fn (Item $sub) => (string) $sub->getLabel(), $item->getSubItems())];
        }

        return $labels;
    }

    public function testAssetsAndOwnershipKeepEverything(): void
    {
        $items = self::items();
        $keys = array_map(fn (Item $item) => $item->getKey(), $items);
        $kept = iterator_to_array((new ActivityMenu(new Activities(['assets', 'ownership'])))->filter($items), false);

        self::assertSame(self::labels(self::items()), self::labels($kept));
        self::assertSame($keys, array_map(fn (Item $item) => $item->getKey(), $kept), 'the same keys: the stored layout still applies');
    }

    public function testAnAgencyHasNoAssociates(): void
    {
        $kept = (new ActivityMenu(new Activities(['management', 'transaction'])))->filter(self::items());

        self::assertSame([
            'estate_widget.occupancy', 'ledger_widget.balance', 'estate_widget.rents',
            ['Gestion locative', ['Biens', 'Baux', 'Messages des locataires']],
            ['Loyers', ['Appels de loyer']],
            ['Travaux', ['Interventions', 'Artisans']],
            ['La société', ['Société']],
            'Réglages',
        ], self::labels($kept));
    }

    public function testWithoutOwnershipNoIndivisionIsShown(): void
    {
        $menu = new ActivityMenu(new Activities(['assets']));
        $labels = self::labels($menu->filter(self::items()));

        self::assertNotContains('estate_widget.ownership', $labels, 'the card goes');
        foreach ($labels as $label) {
            self::assertNotSame('Indivisions', \is_array($label) ? $label[0] : $label, 'the section goes, all its screens with it');
        }
        self::assertFalse($menu->allows(MenuItem::linkToRoute('estate_admin_ownership_statements', [], 'Relevés')));
        self::assertTrue((new ActivityMenu(new Activities(['ownership'])))->allows(MenuItem::linkToCrud(OwnershipInvoice::class, 'Factures partagées')));
    }

    public function testSalesAloneHideTheRentalScreensAndCards(): void
    {
        $menu = new ActivityMenu(new Activities(['transaction']));
        $kept = $menu->filter(self::items());

        self::assertSame([
            'ledger_widget.balance',
            ['Gestion locative', ['Biens']],
            ['La société', ['Société']],
            'Réglages',
        ], self::labels($kept), 'the rents and the works go, sections and cards; the homes, the company and the bank stay');
        self::assertFalse($menu->allows(MenuItem::block('estate_todo', 'estate_widget.todo')));
        self::assertTrue($menu->allows(MenuItem::linkToCrud(Unit::class, 'Biens')));
    }
}
