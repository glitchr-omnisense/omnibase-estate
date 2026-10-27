---
title: The back office
order: 38
---

# The back office

Loaded when omnibase/admin is installed. The CRUDs extend `AbstractEstateCrudController`, which
opens creating, editing, deleting and the three saves to `ROLE_ADMIN` (not only the
super-administrator); the action pages are `#[IsGranted('ROLE_ADMIN')]`. Labels are French.

## The screens

| CRUD | Slug | Actions of its own |
|---|---|---|
| `OrganisationCrudController` | `organisations` | **Lire les registres** (with omnisense/omnistate) |
| `MemberCrudController` | `members` | **Inviter** |
| `AssociateCrudController` | `associates` | **Inviter** |
| `BuildingCrudController` | `buildings` | |
| `UnitCrudController` | `units` | |
| `UnitPhotoCrudController` | `unit-photos` | |
| `LeaseCrudController` | `leases` | **Réviser le loyer** |
| `LeasePartyCrudController` | `lease-parties` | **Inviter** |
| `RentCallCrudController` | `rent-calls` | no new nor edit; **Encaisser** (record a payment), **Appeler les loyers du mois** |
| `PaymentCrudController` | `payments` | read-only |
| `ContractorCrudController` | `contractors` | **Inviter** |
| `WorkOrderCrudController` | `work-orders` | **Confier**, **Clôturer** |
| `DocumentCrudController` | `documents` | |
| `NoticeCrudController` | `notices` | **Envoyer aux locataires** |
| `DeadlineCrudController` | `deadlines` | |
| `ApplicationCrudController` | `applications` | no new; the next steps (shortlist, visit, decline, accept) while open |
| `ActivityCrudController` | `activities` | no new nor edit |
| `InvitationCrudController` | `invitations` | no new nor edit |

## The action pages

`Base\Estate\Controller\Admin\ActionController`, under `/admin/estate`:

| Route | Path | |
|---|---|---|
| `estate_admin_invite` | `/invite/{kind}/{id}` | invite, link token `estate_invite_<kind>_<id>` |
| `estate_admin_rents_call` | `/rents/call` (`?month=`) | call the month's rents |
| `estate_admin_payment` | `/rent/{id}/payment` (GET, POST) | record a payment: amount (`850,00`), method, date, reference |
| `estate_admin_assign` | `/work-order/{id}/assign` (GET, POST) | give to one of the organisation's contractors, a visit date, a note |
| `estate_admin_close` | `/work-order/{id}/close` | close |
| `estate_admin_notice_send` | `/notice/{id}/send` | send to the tenants |
| `estate_admin_application` | `/application/{id}/{shortlist,visit,decline,accept}` (GET, POST) | the next step: a date and a word for a visit, a word to decline, the lease's start (and decline the others) to accept |
| `estate_admin_revise` | `/lease/{id}/revise` (GET, POST) | the IRL revision: the quarter (`T2 2025`) and the value, "calculate", then "apply" |
| `estate_admin_company_refresh` | `/company/{id}/refresh` | the registers (Sirene / RNE, VIES); 404 without omnisense/omnistate |

Links carry a CSRF token in their query (`<action>_<id>`), forms in `_token`. After an action the
page goes back to the back-office page it came from, and only to one under `/admin`.

`MessageController`: `/admin/estate/messages` (`estate_admin_messages`, every lease's thread, the
latest first) and `/admin/estate/messages/{id}` (a lease's thread, answered from the
organisation's name).

## Reading the registers

`Base\Estate\Service\CompanyRecords::refresh(Organisation $organisation): bool` (with
omnisense/omnistate): a valid SIREN read from Sirene / RNE - the legal name, the legal form, the
creation date, the head office's street (capitals put back in their case: "36 A rue de la
Ganzau"), postcode and city ("Saint-Louis") - and the VAT number from VIES (none when VIES does
not know it, as for an SCI letting homes, which is not liable); `registryCheckedAt` set. `false`
without a valid SIREN or for a company the register does not know; a register that does not
answer throws omnistate's `UnavailableException` and nothing changes.

## Dashboard tiles

- `estate_overview` (`OverviewWidgetType`): per organisation, its figures, the rents to revise
  within 45 days, the tenants' messages unread, the new applications, the active leases whose
  insurance is missing or run out.
- `estate_payments` (`PaymentsWidgetType`): the eight latest payments and how, this month's total
  per method, whether online payment is on.

```php
yield MenuItem::block('estate_overview', 'Gestion', 'fa-solid fa-building');
yield MenuItem::block('estate_payments', 'Encaissements', 'fa-solid fa-euro-sign');
```
