---
title: Spaces and access
order: 34
---

# Spaces and access

## Who someone is

`Base\Estate\Service\Spaces` says, from the relations and never from a role stored on the
account, what a user is to the organisations (memoised per request, reset between requests):

| Method | Returns |
|---|---|
| `managed(?User)` | the organisations whose back office they run: every one for a `ROLE_ADMIN`, else those they are a `Member` of |
| `manages(?User, Organisation)` | |
| `associateships(?User)`, `isAssociateOf(?User, Organisation)` | their `Associate` records |
| `tenancies(?User)`, `isPartyOf(?User, Lease)` | the leases they are a party of |
| `contractorships(?User)` | their `Contractor` records |
| `coOwnerships(?User)`, `ownerships(?User)`, `isCoOwnerOf(?User, Ownership)`, `holdersIn(?User, Ownership)` | their `OwnershipShare` records and the ownerships they name - none, and nothing read, while the site does not run `ownership` |
| `of(?User)` | the spaces to offer, in order: `associate` (`estate_associate`), `tenant` (`estate_tenant`), `contractor` (`estate_contractor`), `coowner` (`estate_coowner`, [Shared ownership](usage-ownership.md#the-co-owners-space)) |

## `EstateVoter`

| Attribute | Subject | Granted to (beyond the organisation's management, granted everything) |
|---|---|---|
| `ESTATE_MANAGE` | `Organisation` | nobody else |
| `ESTATE_ASSOCIATE` | `Organisation` | its associates |
| `ESTATE_LEASE_VIEW` | `Lease` | its parties |
| `ESTATE_RENT_VIEW` | `RentCall` | its lease's parties |
| `ESTATE_UNIT_REPORT` | `Unit` | the parties of its current lease |
| `ESTATE_WORK_VIEW` | `WorkOrder` | its contractor, the parties of the unit's current lease, the organisation's associates |
| `ESTATE_WORK_DO` | `WorkOrder` | its contractor |
| `ESTATE_DOCUMENT_VIEW` | `Document` | see below |
| `ESTATE_OWNERSHIP_VIEW` | `Ownership`, `OwnershipInvoice`, `OwnershipCall` | the ownership's co-owners; of the calls, only the co-owner called |

### Documents

The associates see every document of their company. A work order's document: its contractor,
and - when it is tenant-visible - the parties of the unit's current lease. Any other document must
be tenant-visible: a lease's to its parties, a unit's to the parties of its current lease while
they live there.

## The four spaces

- **Associates** (`/associes`, `?societe=<slug>` among several): the figures
  (`Service\Figures::of()`: units, lettable, let, vacant, occupancy, monthly rent, the year's
  rents called and received and the rate, late rents and their total, the last six months side
  by side, open work orders, what works cost this year, how rents were paid, the online part),
  the feed (15 lines), the units, the six next deadlines, the associates and their shares, a link
  to `estate.documents_route` when set, and - with omnibase/ledger - the accounts
  (`Ledger\CompanyAccounts::of()`: the year's compte de résultat so far beside the year before,
  each associate's share of the result by their shares - an SCI is transparent -, the bank
  accounts' balances and their eight latest movements). Read-only.
- **Tenants** (`/locataire`, `?bail=<id>` among several): the lease, its rents (pay online,
  download the notice and the receipt), the certificate, the lease's documents, the insurance
  certificate form, the messages, the work orders of the unit and a form to report one, the five
  last notices.
- **Contractors** (`/artisan`): the work orders given to them, each at `/artisan/intervention/{id}`.
- **The management**: the back office.

A user who is none of these gets a 403 on that space.

## Twig

`Base\Estate\Twig\EstateExtension`:

| | |
|---|---|
| `estate_spaces()` | the signed-in user's spaces (`{key, route}`), for the header and "Mon accès" |
| `estate_online_payment()` | whether online payment is on |
| `x\|euros(round = false)` | cents as the pages write money: `1 234,56 €` (narrow no-break spaces), `—` for `null`; `round` drops the cents |
