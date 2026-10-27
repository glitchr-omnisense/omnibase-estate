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

    # How many days after its due date an unpaid rent is reminded (estate:rent:remind).
    reminder_days:        5

    # The application's documents page, linked from the associates' space. Null: none.
    documents_route:      null
```

| Option | Type | Default | Read by |
|---|---|---|---|
| `user_class` | class | `App\Entity\User` | `Invitations` (accounts it creates), `InvitationController` (usernames taken) |
| `from_email` | string | `%env(default::MAILER_CONTACT)%` | `EstateMailer`; empty: the mailer's own sender |
| `payment_gateway` | string | `stripe` | `OnlinePayments`; empty: no online payment |
| `payment_methods` | list | `card`, `sepa_debit` | `OnlinePayments` |
| `invitation_days` | int, 1 or more | `14` | `Invitations` |
| `reminder_days` | int, 1 or more | `5` | `RentCalls::markLate()` |
| `documents_route` | route name or null | `null` | `AssociateController` |

> [!WARNING]
> Known defect: the info of `reminder_days` names `estate:rent:remind`, a command that does not
> exist, where the reminder is sent by `estate:rent:late`.

## Services

`config/services.php` autowires and autoconfigures `src/` but `DependencyInjection/`, `Entity/`,
`Enum/`, `Model/`, `Exception/`, `Controller/Admin/` and the bundle class, and leaves out
`Service/Books.php` and `Ledger/` without omnibase/ledger (`Base\Ledger\Service\Posting`), and
`Service/CompanyRecords.php` and `Command/CompanyRefreshCommand.php` without omnisense/omnistate.
`Controller/Client/` is tagged `controller.service_arguments`; `Controller/Admin/` too when
omnibase/admin is installed. `Books` is an optional argument of `Payments`, `RentCalls` and
`WorkOrders`; omnitrade's `Registry` of `OnlinePayments`. `Spaces` implements `ResetInterface`.
The e-mails are `@Estate/email/<name>.html.twig`: `rent_call`, `rent_reminder`, `receipt`,
`rent_revision`, `invitation`, `message`, `notice`, `document_added`, `work_reported`,
`work_assigned`, `work_update`, `visit_request`, `application_received`, `application_ack`,
`application_visit`, `application_declined`.
