---
title: Testing
order: 60
---

# Testing

The package's suite is `tests/Controller/CrudRequiredFieldsTest.php`: on omnibase/admin's
`CrudFieldsTestCase`, the back office's required fields against what each record needs. In
omnisense/omnibase's harness, with this checkout and its omnibase/ledger sibling mounted:

```bash
docker run --rm --network none \
    -v "$PWD":/srv/demo/vendor/omnibase/estate:ro -v "$PWD/../ledger":/srv/demo/vendor/omnibase/ledger:ro \
    omnisense/omnibase-harness test estate
```

```
......                                                              6 / 6 (100%)

OK (6 tests, 7 assertions)
```

Beside it, the services' own tests: `Service/ActivitiesTest` (the default, the order, an
unknown activity), `Admin/ActivityMenuTest` (assets and ownership keep every item and its key,
an agency has no associates, sales alone hide the rental screens and cards and the sections
left empty, the management's screens only with it), `Service/ManagementTest` (a managed home's
rent called and collected for its owner with the dimensions owner and unit, the fee and its VAT,
an expense charged, the escrow account, the activity off: nothing), `Service/PayoutsTest` (what an
owner is due, a payout issued, its SEPA file checked against the pain.001.001.03 schema, paid by
hand or by the bank line), `Model/MandateStatementTest` (a month's statement's balances),
`Service/OwnerSpacesTest`, `Admin/ManagementStatementPdfTest`, `Service/BooksTest` (a rent call and a work invoice posted with their unit on
every line), the online payments, the webhook and the dashboard cards. In meyers' container:

```bash
docker exec -w /srv/app/vendor/omnibase/estate meyers-web-1 php /srv/app/vendor/bin/phpunit --do-not-cache-result
```

An application tests the rest through its own pages and commands. The entities need no database: the in-memory example in
[Organisations, homes and leases](concepts.md#rent-calls-and-payments) runs in the harness
image as it is.
