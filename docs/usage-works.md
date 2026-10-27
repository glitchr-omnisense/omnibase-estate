---
title: Works, documents and messages
order: 31
---

# Works, documents and messages

## Work orders

`Base\Estate\Service\WorkOrders` follows a problem from its report to its invoice. Every step is
a line of the order's timeline (`WorkOrderEvent`), a line of the associates' feed, and an e-mail
to whoever it concerns.

| Step | Method | Status | E-mails |
|---|---|---|---|
| reported (by a tenant from their space, or the management) | `report(Unit, ?User, string $byName, string $title, string $description, string $category = 'Autre', Urgency $urgency = NORMAL)` | `REPORTED` | the managers (the members and the organisation's address): "[Intervention #12] ..." |
| given to a contractor | `assign(WorkOrder, Contractor, ?\DateTimeImmutable $visitAt, ?User, string $byName = 'Gestion', ?string $note = null)` | `SCHEDULED` with a visit date, else `ASSIGNED` | the contractor; the current lease's tenants |
| the contractor's word | `update(WorkOrder, ?User, string $byName, ?$visitAt, ?int $quote, ?string $report, bool $done, ?int $invoice, ?string $invoiceNumber)` | `SCHEDULED` for a new visit date, `DONE` when done | the managers; the tenants for a visit date or the end |
| a note | `note(WorkOrder, ?User, string $byName, string $body)` | - | - |
| closed by the management | `close(WorkOrder, ?User, string $byName = 'Gestion')` | `CLOSED`, `closedAt` | - ; the invoice posted to the books |

`update()` writes only what changed; with nothing new it does nothing. A tenant's report form
offers the categories Plomberie, Chauffage, Électricité, Serrurerie, Menuiserie, Humidité, Parties
communes, Autre, and the three urgencies.

The contractor's page (`/artisan/intervention/{id}`, `ESTATE_WORK_DO`) shows the address, the
access, who lives there and the timeline, with two forms: the visit date, the quote, the invoice
and its number, the report and "done"; and a file (quote, invoice, photo).

## Documents

`Base\Estate\Service\Documents`:

- `insurance(Lease, File, \DateTimeImmutable $until, User, string $name)` - a tenant's home
  insurance certificate: a document of kind `INSURANCE` on the lease, valid until that date; the
  lease's `insuranceUntil` moves forward with it; an activity line; the management told.
- `work(WorkOrder, DocumentKind, File, ?string $title, User, string $name, bool $tenantVisible = false)` -
  a contractor's quote, invoice or photo on a work order, titled "Devis — <title>" when none is
  given; the management told.

Files are streamed by `/fichier/{id}` (`estate_document`) to whoever may see them
([Spaces and access](usage-spaces.md#documents)), inline, named after the document's title; the
uploads stay out of `public/`.

## Messages

`Base\Estate\Service\Messages` keeps the thread between a lease's tenants and the management:

- `post(Lease, string $body, bool $fromManagement, ?User $author, string $authorName)` - kept,
  then e-mailed to the other side: every tenant of the lease (reply-to the organisation), or the
  organisation's address;
- `read(Lease, bool $byManagement)` - the thread, the other side's messages marked read.

The tenant writes from their space; the management from `/admin/estate/messages`, every lease's
thread, the latest first, with its unread count.

## Notices

A `Notice` (the building's or every building's) is sent from the back office
(`estate_admin_notice_send`): one e-mail to each tenant of the current leases of the
organisation's units - of that building only when it names one -, `sentAt` set, an activity
line. The tenants see the five last sent in their space.
