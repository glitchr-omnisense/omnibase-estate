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

The package has no tests of its services: an application tests them through its own pages and
commands. The entities need no database: the in-memory example in
[Organisations, homes and leases](concepts.md#rent-calls-and-payments) runs in the harness
image as it is.
