---
title: Organisations, homes and leases
order: 20
---

# Organisations, homes and leases

Amounts are integers in cents. Every record hangs off an `Organisation`, directly or through
its unit or lease, with `onDelete: CASCADE`.

## The organisation and its people

| Entity | Table | Fields |
|---|---|---|
| `Organisation` | `estate_organisation` | `name` (not blank), `slug` (unique), `legalName`, `legalForm`, `siren`, `vatNumber`, `registryCheckedAt`, `foundedOn`, `address`, `postcode`, `city`, `email` (the management's address: e-mails to it, replies from tenants), `phone`, `iban`, `bookId` (its omnibase/ledger book, when it keeps one); `getFullAddress()` |
| `Member` | `estate_member`, unique on organisation and user | the management team: `name`, `email`, `title`, `user` |
| `Associate` | `estate_associate` | `firstName`, `lastName`, `email`, `phone`, `shares`, `quality` (`MANAGER`, `ASSOCIATE`), `since`, `public`, `bio`, `portrait` (4 MB), `user` |
| `Contractor` | `estate_contractor` | `company`, `contactName`, `trade`, `siret`, `email`, `phone`, `iban`, `user` |
| `Deadline` | `estate_deadline` | `title`, `dueOn`, `yearly` (`true`), `notes`; `next(?$today)` - the next date, a yearly one moved year after year |
| `Activity` | `estate_activity` | the associates' feed: `kind` (`ActivityKind`), `message`, `amount`, `unit`, `happenedAt` |

`user` links a person to their account once they accepted an invitation (`SET NULL` when the
account goes; a member's cascades).

## Homes

`Building` (`estate_building`): `name`, `address`, `postcode`, `city`, `builtIn`, `access` (how
to get in, shown to contractors).

`Unit` (`estate_unit`):

| Field | Meaning |
|---|---|
| `kind` | `UnitKind`: `HOUSE`, `APARTMENT` (default), `STUDIO`, `PARKING`, `COMMERCIAL` |
| `name`, `slug` (unique) | the public page is `/biens/{slug}` |
| `building`, `address`, `postcode`, `city`, `district`, `door` | where |
| `surface` (decimal 7,2), `rooms`, `bedrooms`, `floor`, `heating` | |
| `energyClass`, `energyValue`, `climateClass` | the DPE's letters and value |
| `description`, `features` (json list), `furnished` | |
| `rent`, `charges`, `deposit` | what a new lease takes |
| `published` | on the public site |
| `availableFrom` | free from that date; `null`: not offered (`isAvailable()`) |
| `facade` | the card's colour on the public site (`ochre` by default) |
| `position` | |
| `photos` | `UnitPhoto`s (12 MB each, caption, position) |
| `leases` | newest first |

`getCurrentLease(?$on)`, `isLet(?$on)`, `getCover()`.

## Leases

`Lease` (`estate_lease`):

| Field | Meaning |
|---|---|
| `unit`, `reference` (unique) | `MA-2026-003` when made from an application |
| `startOn`, `endOn`, `noticeOn` | |
| `rent`, `charges`, `deposit` | taken from the unit when created; `getMonthly()` is rent and charges |
| `dueDay` | 1 to 28, `5` by default: the rent call's due date |
| `paymentMethod` | `PaymentMethod`: `TRANSFER`, `CARD`, `SEPA_DEBIT`, `CHEQUE`, `CASH` |
| `status` | `LeaseStatus`: `ACTIVE`, `NOTICE`, `ENDED` |
| `insuranceUntil` | the tenants' home insurance, from their certificate |
| `indexQuarter`, `indexValue` | the IRL of reference ("T2 2025", decimal) |
| `revisedOn` | the last revision |
| `parties` | `LeaseParty`s: `role` (`TENANT`, `COTENANT`, `GUARANTOR`), name, e-mail (lowercased), phone, IBAN, user |
| `rentCalls` | newest first |

`getTenants()` (the tenants and co-tenants), `getTenantNames()`, `isRunningOn($on)` (not ended,
started, not past its end).

## Rent calls and payments

`RentCall` (`estate_rent_call`, unique on lease and period): `period` (the month's first day),
`dueOn` (the lease's due day of that month), `reference` (`AVIS-<YYYY-MM>-<lease reference>`,
unique), `rent` and `charges` (the lease's when called), `paid`, `status` (`RentStatus`:
`CALLED`, `PARTIAL`, `PAID`, `LATE`), `calledAt`, `paidAt`, `remindedAt`, `receiptNumber`
(`Q-2026-0007`), `payments`. `getTotal()`, `getRemaining()`, `isPaid()`, `isLate(?$today)`.
`addPayment()` adds to `paid`: `PAID` (with `paidAt` the payment's date) once the total is
reached, `PARTIAL` before.

`Payment` (`estate_payment`): `amount`, `method`, `paidOn`, `reference` (unique: a payment is
recorded once per reference).

```php
use Base\Estate\Entity\Lease;
use Base\Estate\Entity\LeaseParty;
use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\Payment;
use Base\Estate\Entity\RentCall;
use Base\Estate\Entity\Unit;
use Base\Estate\Enum\PaymentMethod;

$organisation = new Organisation('SCI Les Tilleuls', 'les-tilleuls');
$unit = (new Unit($organisation, 'Appartement 3', 'appartement-3'))->setRent(85000)->setCharges(6000)->setDeposit(85000);
$lease = (new Lease($unit, 'LT-2026-001', new DateTimeImmutable('2026-01-01')))->setDueDay(5);
$lease->addParty(new LeaseParty('Jean', 'Dupont', 'Jean.Dupont@example.org'));

$call = new RentCall($lease, new DateTimeImmutable('2026-10-17'));
echo $call->getReference(), ' due ', $call->getDueOn()->format('Y-m-d'), ' total ', $call->getTotal(), "\n";

$call->addPayment(new Payment($call, 50000, PaymentMethod::TRANSFER, new DateTimeImmutable('2026-10-03')));
echo $call->getStatus()->value, ' remaining ', $call->getRemaining(), ' late on the 6th: ', var_export($call->isLate(new DateTimeImmutable('2026-10-06')), true), "\n";
$call->addPayment(new Payment($call, 41000, PaymentMethod::CARD, new DateTimeImmutable('2026-10-08')));
echo $call->getStatus()->value, ' paid at ', $call->getPaidAt()->format('Y-m-d'), "\n";
echo $lease->getTenantNames(), ' | running on 2026-10-01: ', var_export($lease->isRunningOn(new DateTimeImmutable('2026-10-01')), true), "\n";
```

Run in omnisense/omnibase's harness image, in memory:

```
AVIS-2026-10-LT-2026-001 due 2026-10-05 total 91000
partial remaining 41000 late on the 6th: true
paid paid at 2026-10-08
Jean Dupont | running on 2026-10-01: true
```

## Works, documents, messages

| Entity | Fields |
|---|---|
| `WorkOrder` (`estate_work_order`) | `unit`, `reportedBy`, `title`, `description`, `category` (`Autre`), `urgency` (`LOW`, `NORMAL`, `URGENT`), `status` (`REPORTED`, `ASSIGNED`, `SCHEDULED`, `DONE`, `CLOSED`, `CANCELLED`), `contractor`, `visitAt`, `quoteAmount`, `invoiceAmount`, `invoiceNumber`, `report`, `createdAt`, `closedAt`, `events` (`WorkOrderEvent`: author, name, body, date) |
| `Document` (`estate_document`) | `organisation` and one of `unit`, `lease`, `workOrder`; `kind` (`LEASE`, `INVENTORY`, `DIAGNOSTIC`, `RULES`, `INSURANCE`, `QUOTE`, `INVOICE`, `PHOTO`, `OTHER`), `title`, `file` (PDF or image, 20 MB, outside `public/`), `tenantVisible` (`true`), `validUntil`, `uploadedBy`, `uploadedByName` |
| `Message` (`estate_message`) | `lease`, `author`, `authorName`, `fromManagement`, `body`, `createdAt`, `readAt` |
| `Notice` (`estate_notice`) | a notice to the tenants: `organisation`, `building` (none: all), `title`, `body`, `sentAt` |
| `Application` (`estate_application`) | [The public site and applications](usage-applications.md) |
| `Invitation` (`estate_invitation`) | [Invitations](invitations.md) |

`ActivityKind`: `RENT_CALLED`, `RENT_PAID`, `RENT_LATE`, `LEASE_STARTED`, `LEASE_ENDED`,
`WORK_REPORTED`, `WORK_ASSIGNED`, `WORK_DONE`, `DOCUMENT`, `NOTICE`, `OTHER`. The services write
one line per event (`Service\Activities::log()`).
