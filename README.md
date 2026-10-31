# omnibase/estate

Property management for the sites built on [omnisense/omnibase](https://github.com/glitchr-omnisense/omnibase):
the organisations that own or manage homes (an SCI, an agency), their buildings and units,
associates, leases and tenants, rent calls, receipts and online payment, contractors and work
orders, invitations, and a private space for each of those people - posting to
[omnibase/ledger](https://github.com/glitchr-omnisense/omnibase-ledger) when the organisation
keeps its books there. With the `ownership` activity, shared ownerships (indivisions): co-owners'
shares over time, shared invoices divided and called for funds, each co-owner's statement and
space. With the `management` activity, property management for third parties (gérance):
owners and their mandates, rents collected to the owners' accounts, the agency's fees with VAT,
expenses, owners' statements, payouts and their SEPA file, the escrow account, the owner's space.
With the `transaction` activity, sales for third parties: sale mandates, buyers matched, visits,
offers, the sale's French steps (compromis, withdrawal period, conditions, acte), the fee at the
acte with its VAT, the deposit, the seller's and the buyer's spaces. Its words on the pages and in the e-mails are French: it was written for French
residential lettings (the loi du 6 juillet 1989, the IRL revision, the quittance).

Nobody signs up: the management invites an associate, a tenant, a contractor or a member of its
team, and what each person may open comes from what they are to the organisation, never from a
role stored on the account.

## Requirements

- PHP 8.2+, `doctrine/orm` 2.15 or 3
- `omnisense/omnibase` 3.x or 4.0, `omnibridge/symfony` 1.x, `omnibase/admin`
- Symfony 7 or 8 (`config`, `dependency-injection`, `form`, `http-kernel`, `mailer`, `security-bundle`)
- Suggested: `omnibase/ledger` (the books), `omnisense/omnitrade` with `omnitrade/stripe` (rents
  paid online), `dompdf/dompdf` (PDF notices, receipts and statements), `omnisense/omnistate`
  (companies read from the public registers), `omnibase/drive` (a shared ownership's and an owner's
  cloud folders), `omnibank/files` (the owners' payouts as a SEPA file)

## Installation

```bash
composer require omnibase/estate
```

```php
// config/bundles.php
Base\Admin\AdminBundle::class => ['all' => true],
Base\Estate\EstateBundle::class => ['all' => true],
```

```yaml
# config/routes.yaml
estate_controller:
    resource: "@EstateBundle/src/Controller"
    type: attribute
    prefix: /
```

Then the schema (`doctrine:migrations:diff`, `doctrine:migrations:migrate`) and the crons:

```cron
0 6 25 * *  bin/console estate:rent:call              # next month's rents, on the 25th
0 7 * * *   bin/console estate:rent:late              # late rents marked, tenants reminded once
0 3 * * *   bin/console estate:applications:purge     # applications decided over three months ago
0 4 * * 1   bin/console estate:company:refresh        # the companies read again from the registers
```

## Documentation

The manual is in [docs/](docs/index.md): [installation](docs/installation.md),
[organisations, homes and leases](docs/concepts.md), [rents](docs/usage-rents.md),
[shared ownership](docs/usage-ownership.md), [property management](docs/usage-management.md),
[sales](docs/usage-transaction.md),
[works, documents and messages](docs/usage-works.md),
[the public site and applications](docs/usage-applications.md),
[invitations](docs/invitations.md), [spaces and access](docs/usage-spaces.md),
[the back office](docs/back-office.md), the [configuration reference](docs/configuration.md)
and [testing](docs/testing.md).

## Licence

MIT: the text of the license is in [LICENSE](LICENSE).
