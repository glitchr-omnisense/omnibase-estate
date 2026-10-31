---
title: Installation
order: 10
---

# Installation

## Requirements

What `composer.json` requires: PHP 8.2 or later, `doctrine/orm` 2.15 or 3, `omnisense/omnibase`
3.x or 4.0, `omnibridge/symfony` 1.x, `omnibase/admin`, and `symfony/config`,
`dependency-injection`, `form`, `http-kernel`, `mailer` and `security-bundle` 7 or 8.

What it suggests, each loaded only when present (`config/services.php`):

| Package | Adds |
|---|---|
| `omnibase/ledger` | rent calls, payments and work invoices posted to the organisation's book (`Service\Books`); bank lines reconciled with rent calls pay them (`Ledger\BankSettlement`), and no longer once taken back (`Ledger\BankUnsettlement`); associates read the books (`Ledger\AssociateBookAccess`) and their accounts in their space (`Ledger\CompanyAccounts`) |
| `omnisense/omnitrade` with `omnitrade/stripe` | rents paid online by card or SEPA debit (`Service\OnlinePayments`) |
| `dompdf/dompdf` | notices, receipts and certificates as PDF (without it, printable pages) |
| `omnibase/drive` | a shared ownership's folder, shared with its co-owners (`Drive\OwnershipFolders`); an owner's folder under management, shared with them read-only (`Drive\MandatorFolders`); a sale mandate's folder, shared read-only with its sellers and its buyer (`Drive\SaleFolders`) |
| `omnibank/files` | the owners' payouts as a SEPA credit transfer file, pain.001.001.03 (`Management::sepa()`) |
| `omnisense/omnistate` (with `omnistate/annuaire-entreprises`, `omnistate/vies`) | a company's identity read from the registers (`Service\CompanyRecords`, `estate:company:refresh`) |

## The bundle

```php
// config/bundles.php
return [
    // ...
    Base\BaseBundle::class => ['all' => true],
    Base\Admin\AdminBundle::class => ['all' => true],
    Base\Estate\EstateBundle::class => ['all' => true],
];
```

`EstateBundle` aliases `Base\Estate\Entity\*` to `App\Entity\Estate\*` and the repositories to
`App\Repository\Estate\*` unless the application declares its own classes there; its
`templates/` is `@Estate`.

## The routes

```yaml
# config/routes.yaml
estate_controller:
    resource: "@EstateBundle/src/Controller"
    type: attribute
    prefix: /
```

| Route | Path | Who |
|---|---|---|
| `estate_units` | `/biens` | public |
| `estate_unit` | `/biens/{slug}` (GET, POST: visit request) | public |
| `estate_unit_apply` | `/biens/{slug}/candidature` | public |
| `estate_invitation` | `/invitation/{token}` (48 hex) | public |
| `estate_associate` | `/associes` (`?societe=<slug>`) | an associate |
| `estate_tenant` | `/locataire` (`?bail=<id>`; GET, POST) | a tenant |
| `estate_rent_pay` | `/locataire/loyer/{id}/payer` (POST) | a tenant of the lease |
| `estate_rent_paid` | `/locataire/loyer/{id}/retour` | the payment's return |
| `estate_rent_document` | `/locataire/loyer/{id}/avis` or `/quittance` | a tenant of the lease |
| `estate_tenant_attestation` | `/locataire/bail/{id}/attestation` | a tenant of the lease |
| `estate_contractor` | `/artisan` | a contractor |
| `estate_work_order` | `/artisan/intervention/{id}` (GET, POST) | the work order's contractor |
| `estate_coowner` | `/indivision` (`?indivision=<id>`) | a co-owner (the `ownership` activity) |
| `estate_coowner_statement` | `/indivision/{id}/releve/{period}` | a co-owner of the ownership |
| `estate_owner` | `/proprietaire` (`?mandat=<id>`) | an owner under management (the `management` activity) |
| `estate_owner_statement` | `/proprietaire/{id}/compte-rendu/{period}` | the mandate's owner |
| `estate_seller` | `/vendeur` (`?mandat=<id>`) | a seller of a sale mandate (the `transaction` activity) |
| `estate_buyer` | `/acquereur` | a buyer (the `transaction` activity) |
| `estate_transaction_document` | `/vente/document/{id}` | the sale's sellers and buyer |
| `estate_document` | `/fichier/{id}` | whoever may see the document |
| `estate_admin_*` | `/admin/estate/...` | `ROLE_ADMIN` ([The back office](back-office.md)) |

The spaces need a signed-in user (`ROLE_USER`); the core's firewall provides the sign-in.

## Configuration

```yaml
# config/packages/estate.yaml (every key optional)
estate:
    user_class: App\Entity\User
    from_email: '%env(default::MAILER_CONTACT)%'
    payment_gateway: stripe
    payment_methods: [card, sepa_debit]
    invitation_days: 14
    reminder_days: 5
    documents_route: null
```

## The schema

```bash
bin/console doctrine:migrations:diff
bin/console doctrine:migrations:migrate
```

Tables `estate_*`: organisation, building, unit, unit_photo, associate, member, lease,
lease_party, rent_call, payment, contractor, work_order, work_order_event, document, notice,
deadline, activity, application, message, invitation; with the shared ownerships (read only when
the site runs `ownership`, but mapped: migrate them anyway) ownership, ownership_unit,
ownership_share, ownership_invoice, ownership_call, ownership_payment; with property management
(read only when the site runs `management`, mapped all the same) mandator, mandate, mandate_unit,
mandate_fee, mandate_expense, mandate_payout; with sales (read only when the site runs
`transaction`) sale_mandate, sale_mandate_seller, buyer, visit, offer, sale, sale_event. omnibase/ledger's `ledger_bank_account` has an
`escrow` column (the escrow account).

## The crons

```cron
0 6 25 * *  bin/console estate:rent:call              # next month's rents, on the 25th
0 7 * * *   bin/console estate:rent:late              # late rents marked, tenants reminded once
0 3 * * *   bin/console estate:applications:purge     # applications decided over three months ago
0 4 * * 1   bin/console estate:company:refresh        # the companies read again from the registers
```

| Command | Options |
|---|---|
| `estate:rent:call` | `--month=YYYY-MM` (next month by default), `--no-mail`; run twice, it calls nothing twice |
| `estate:rent:late` | `--no-mail` |
| `estate:applications:purge` | `--months=3` |
| `estate:company:refresh` | - (with omnisense/omnistate); exit 1 when a register did not answer |
