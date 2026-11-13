---
title: Configuration reference
order: 40
---

# Configuration reference

## `estate:`

`Base\Estate\DependencyInjection\EstateConfiguration`, dumped with Symfony's
`YamlReferenceDumper` in omnisense/omnibase's harness image:

```yaml
estate:

    # What the business does, combinable: assets (its own property), ownership (a shared ownership), management (for third parties), transaction (sales). The back office shows what belongs to them.
    activities:

        # Default:
        - assets

    # The application's user entity: invitations create accounts of this class.
    user_class:           App\Entity\User

    # Who the e-mails to tenants, associates and contractors come from.
    from_email:           '%env(default::MAILER_CONTACT)%'

    # The omnitrade gateway rents are paid online through. Empty: no online payment.
    payment_gateway:      stripe

    # What a tenant may pay with online, as omnitrade names it.
    payment_methods:

        # Defaults:
        - card
        - sepa_debit

    # How long an invitation link stays valid.
    invitation_days:      14

    # How many days after its due date an unpaid rent is marked late and reminded (estate:rent:late).
    reminder_days:        5

    # The application's documents page, linked from the associates' space. Null: none.
    documents_route:      null

    # Leases and inventories signed electronically, with omnisense/omnisign (Signature\LeaseSignatures).
    signature:

        # The omnisign gateway (omnisign.gateways.<name>) leases are signed through: docuseal (self-hosted) in development, yousign in production. Null: no electronic signature.
        gateway:              null

        # A template of the provider's to sign instead of the lease's PDF and its signature page: DocuSeal's open-source edition signs its own templates only. Its roles: Bailleur, Locataire 1, Locataire 2..., Garant 1...
        template:             null

        # The same for the inventory of fixtures (roles Bailleur, Locataire 1...).
        inventory_template:   null

        # The same for a guarantor's deed (Signature\SuretySignatures, role Garant): the provider's template must then carry the deed's statements itself.
        surety_template:      null

        # The eIDAS level asked: simple (any provider), advanced (Yousign, DocuSign).
        level:                simple # One of "simple"; "advanced"

    # The homes' stories: the ready-to-post images made for the networks.
    story:

        # A TTF/OTF file for the images' titles and labels (bold). Null: DejaVu Sans Bold where the system has it.
        font:                 null

        # A TTF/OTF file for the images' second line. Null: the bold font's regular sibling, else the bold one.
        font_regular:         null

        # The company's mark on the images' band: a PNG or JPEG file (transparent PNG best). Null: none, the company's name is written.
        mark:                 null

        # The band's and the labels' colour, #rrggbb.
        color:                '#1f2328'
```

| Option | Type | Default | Read by |
|---|---|---|---|
| `activities` | list of `assets`, `ownership`, `management`, `transaction`, at least one | `[assets]` | `Service\Activities` (`ActivityMenu`, the Twig functions) |
| `user_class` | class | `App\Entity\User` | `Invitations` (accounts it creates), `InvitationController` (usernames taken) |
| `from_email` | string | `%env(default::MAILER_CONTACT)%` | `EstateMailer`; empty: the mailer's own sender |
| `payment_gateway` | string | `stripe` | `OnlinePayments`; empty: no online payment |
| `payment_methods` | list | `card`, `sepa_debit` | `OnlinePayments` |
| `invitation_days` | int, 1 or more | `14` | `Invitations` |
| `reminder_days` | int, 1 or more | `5` | `RentCalls::markLate()` |
| `documents_route` | route name or null | `null` | `AssociateController` |
| `signature.gateway` | string or null | `null` | `Signature\LeaseSignatures`: the omnisign gateway; null: no electronic signature |
| `signature.template`, `signature.inventory_template`, `signature.surety_template` | string or null | `null` | a provider's template signed in place of the PDF (DocuSeal's open-source edition) |
| `signature.level` | `simple`, `advanced` | `simple` | the eIDAS level asked |
| `story.font`, `story.font_regular` | font file or null | `null` (DejaVu Sans) | `StoryImages` (FreeType reads TTF, OTF and WOFF) |
| `story.mark` | image file or null | `null` | `StoryImages` |
| `story.color` | `#rrggbb` | `#1f2328` | `StoryImages` |

## Activities

`estate.activities` says what the business does, combinable (`Base\Estate\Enum\Activity`):

| Activity | What it is |
|---|---|
| `assets` | its own property: a property company (SCI), several companies |
| `ownership` | a shared ownership (indivision): co-owners' shares, shared invoices, calls for funds |
| `management` | property management for third parties: mandates, owners' statements, fees with VAT |
| `transaction` | sales: sale mandates, buyers, offers, fees |

SCI Exemple sets `[assets, ownership]`; an agency `[management, transaction]`. The
default, `[assets]`, keeps a site as it was. `Base\Estate\Service\Activities` answers `has()`,
`any()` and `all()` (in the enum's order); in Twig, `estate_activity('ownership')` and
`estate_activities()`.

What the back office shows follows it ([The back office](back-office.md#activities)). Ownership
has its screens, postings and space ([Shared ownership](usage-ownership.md)), management too
([Property management for third parties](usage-management.md)), and transaction
([Sales for third parties](usage-transaction.md)).

## Services

`config/services.php` autowires and autoconfigures `src/` but `DependencyInjection/`, `Entity/`,
`Enum/`, `Model/`, `Exception/`, `Controller/Admin/` and the bundle class, and leaves out
`Service/Books.php`, `Ledger/` and `Command/BooksUnitsCommand.php` without omnibase/ledger (`Base\Ledger\Service\Posting`), and
`Service/CompanyRecords.php` and `Command/CompanyRefreshCommand.php` without omnisense/omnistate.
`Controller/Client/` is tagged `controller.service_arguments`; `Controller/Admin/` too when
omnibase/admin is installed. `Books` is an optional argument of `Payments`, `RentCalls` and
`WorkOrders`; omnitrade's `Registry` of `OnlinePayments`. `Spaces` implements `ResetInterface`.
The e-mails are `@Estate/email/<name>.html.twig`: `rent_call`, `rent_reminder`, `receipt`,
`rent_revision`, `revisions_due`, `lease_signature`, `surety_signature`, `invitation`, `message`, `notice`, `document_added`, `work_reported`,
`work_assigned`, `work_update`, `visit_request`, `application_received`, `application_ack`,
`application_visit`, `application_declined`.
