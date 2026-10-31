---
title: The back office
order: 38
---

# The back office

Loaded when omnibase/admin is installed. The CRUDs extend `AbstractEstateCrudController`, which
opens creating, editing, deleting and the three saves to `ROLE_ADMIN` (not only the
super-administrator); the action pages are `#[IsGranted('ROLE_ADMIN')]`. Labels are French.

## Activities

The menu items and dashboard cards that belong to an activity show only when the site runs it
(`estate.activities`, see [Configuration](configuration.md#activities)). The dashboard
controller passes its items through `Base\Estate\Admin\ActivityMenu::filter()`, which keeps
their labels, icons, order and keys (the layout the team arranged is stored by key):

```php
#[Required]
public function setActivityMenu(ActivityMenu $activityMenu): void { $this->activityMenu = $activityMenu; }

public function configureWidgetItems(): iterable
{
    return $this->activityMenu->filter($this->items());
}
```

| Belongs to | Items |
|---|---|
| assets, ownership, management (they let homes) | leases, tenants and guarantors, applications, rent calls, payments, work orders, contractors, notices, the homes' documents, the tenants' messages; the cards `estate_overview`, `estate_occupancy`, `estate_rents`, `estate_late`, `estate_works`, `estate_todo`, `estate_payments` |
| assets | the associates |
| ownership | the indivisions, their shares, shared invoices, calls for funds (`Ownership*`), the statements page (`estate_admin_ownership_statements`), the card `estate_ownership` ([Shared ownership](usage-ownership.md#the-back-office)) |
| management | the owners, their mandates, the expenses charged to them, their payouts (`Mandator`, `Mandate*`), the statements and payouts pages (`estate_admin_management_statements`, `estate_admin_management_payouts`), the card `estate_management` ([Property management](usage-management.md#the-back-office)) |
| every activity | the homes, their photos, the buildings, the company, the team, deadlines, invitations, the activity log, and everything not estate's (the books, the site) |

A section left without items is left out. `ActivityMenu::allows($item)` and `activitiesOf($item)`
answer for one item.

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

## Dashboard cards

Palette cards of omnibase/admin (a superadmin adds them from "+ Ajouter un widget", a dashboard
controller places them by name), in admin's dashboard card - the title above the white panel,
tinted rows, legends and axes -, for every organisation together, titled by their keys in the
`admin` domain (`estate_widget.*`), their words in the `estate` domain (`dashboard.*`). A compact
card sits two to a row on a phone.

| Block name | Card | |
|---|---|---|
| `estate_occupancy` (`Dashboard\OccupancyWidgetType`) | the homes let and vacant as a donut, the vacant named | compact |
| `estate_late` (`Dashboard\LateRentsWidgetType`) | what is late, by whom, since when, oldest first | compact |
| `estate_works` (`Dashboard\WorksWidgetType`) | the interventions in progress and their status, the year's works' cost | compact |
| `estate_rents` (`Dashboard\RentsWidgetType`) | rents called (a light bar) and collected (drawn inside it) month by month, 6 or 12 months switched in the card (`months` parameter: the one shown first), the legend, an axis, a tooltip per month, a table for screen readers, the period's rate | |
| `estate_todo` (`Dashboard\TodoWidgetType`) | what waits, as rows each with its icon, a short text and its action: the month's rents to call, applications, tenants' messages, late rents, rents to revise, insurance certificates | |
| `estate_payments` (`PaymentsWidgetType`) | this month's money in, per method, the latest payments, whether online payment is on | |

`estate_overview` (`OverviewWidgetType`), the earlier all-in-one card, is kept for the dashboards
that place it; it is not in the palette.

```php
yield MenuItem::block('estate_occupancy', 'estate_widget.occupancy', 'fa-solid fa-house-circle-check')->setSize(1);
yield MenuItem::block('estate_rents', 'estate_widget.rents', 'fa-solid fa-chart-column')->setSize(2)->setParams(['months' => 12]);
yield MenuItem::block('estate_todo', 'estate_widget.todo', 'fa-solid fa-list-check')->setSize(2);
yield MenuItem::block('estate_payments', 'estate_widget.payments', 'fa-solid fa-money-bill-wave')->setSize(2);
```

`Figures::months($organisation, $count)` gives the rents called and received month by month.
