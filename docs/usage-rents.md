---
title: Rents
order: 30
---

# Rents

## Calling the rents

`Base\Estate\Service\RentCalls::call(\DateTimeImmutable $month, ?Organisation $organisation = null, bool $notify = true)`
makes one `RentCall` per lease running in that month (`LeaseRepository::findRunningIn()`), and
none twice for a lease and a month. For each (`callOne()`):

- `calledAt` is a week before the month, 9:00 (or now, if later) - when notices go out;
- an activity line "Loyer de octobre 2026 appelé";
- posted to the books, when the organisation keeps them ([below](#the-books));
- with `$notify`, the notice (`rent_call` e-mail, "Avis d'échéance") to every tenant, reply-to
  the organisation's address.

`estate:rent:call [--month=YYYY-MM] [--no-mail]` runs it for every organisation (next month by
default); the back office's button calls the current month.

## Late rents and reminders

`RentCalls::markLate(\DateTimeImmutable $today, bool $remind = true)`: every call past due and not
paid becomes `LATE` (from `CALLED` or `PARTIAL`, with an activity line); a call that was not
reminded yet and whose due date is `estate.reminder_days` (5) days past is reminded once
(`rent_reminder` e-mail, `remindedAt` set). `estate:rent:late [--no-mail]`, daily.

## Payments

`Base\Estate\Service\Payments::record(RentCall $call, int $amount, PaymentMethod $method, ?\DateTimeImmutable $paidOn = null, ?string $reference = null, bool $notify = true, bool $post = true): Payment`:

- a non-positive amount is an `InvalidArgumentException`;
- a reference already recorded returns that payment: nothing twice;
- the payment is added to the call; an activity line "Loyer reçu (virement)";
- the call paid in full: its receipt numbered (`Q-<year>-<0001>`, per year of the period, in
  the order rents are paid); posted to the books (unless `$post` is false: a bank line already
  posted it); with `$notify`, the receipt e-mailed to the tenants, the PDF attached when dompdf
  is installed.

`Payments::cancel(Payment $payment)`: a payment recorded in error taken back - off its call, which
is due again (`RentCall::removePayment()`: called, or partial with what is left), its receipt
number kept for the next payment. Nothing is posted: the ledger side is the caller's.

The back office records a payment by hand (amount, method, date, reference).

## Receipts, notices and certificates

`Base\Estate\Service\Receipts` renders `@Estate/pdf/receipt.html.twig` (quittance),
`notice.html.twig` (avis d'échéance) and `attestation.html.twig` (the certificate a tenant hands
a bank, the CAF or a future landlord), as PDF with dompdf (A4, Helvetica, no remote resources),
or as HTML without it. The tenant downloads the notice, the receipt once paid, and the
certificate only when nothing past due is owed.

## Online payment

`Base\Estate\Service\OnlinePayments`, through omnisense/omnitrade's `Registry` and the gateway
`estate.payment_gateway` (`stripe`): available when the registry has that gateway.

- `start(RentCall $call, string $method, string $returnUrl, string $cancelUrl, ?string $email = null, ?\DateTimeImmutable $now = null)` -
  a purchase of the remaining amount in EUR, referenced by the call, described "Loyer 10/2026 —
  <home>", the method among `estate.payment_methods` (`card`, `sepa_debit`), metadata
  `rent_call`, locale `fr`: the provider's page. Idempotent per call, amount already paid, method
  and ten minutes (`<reference>-<paid>-<method>-<YmdH><minutes / 10>`): a second click opens the
  same page; another method, or a new try after a refusal, asks again (the provider keeps a key's
  answer, a refusal too). The tenant's page posts it with a full page load (`reload`): the answer
  is a redirect to the provider's origin.
- `complete(RentCall $call, string $reference)` - back from the provider (`?session=`): recorded
  as a card or SEPA payment once the transaction is paid. A SEPA debit is paid later.
- `paidBy(string $body, array $headers)` - the provider's webhook, checked against its signature
  (`InvalidNotificationException` otherwise): `['call' => <rent call id>, 'reference' => <session>]`
  for a paid session (Stripe's `checkout.session.completed` or `async_payment_succeeded`) whose
  metadata names a rent call, null for anything else.

`POST /estate/payments/webhook` (`estate_payment_webhook`, public) takes it: a rent call not paid
yet is recorded through `complete()`, under the session's reference - the tenant's return records
nothing twice. 204; 400 for a signature that does not match; 404 without a gateway. The rent is
paid even when the tenant never came back: a page closed, a SEPA debit through days later. The
endpoint is declared at the provider (Stripe: Developers → Webhooks, the four
`checkout.session.*` events) with its signing secret in the gateway's `webhook_secret`
(`STRIPE_WEBHOOK_SECRET`); locally, `stripe listen --forward-to
https://localhost:<port>/estate/payments/webhook --skip-verify` prints that secret.

The tenant's "Payer" is a POST with the token `rent_pay_<id>`; refused for a paid call or when
online payment is off.

## The IRL revision

`Base\Estate\Service\RentRevisions` (loi du 6 juillet 1989, art. 17-1):

- `nextOn(Lease $lease, ?$today)` - a year after the start or the last revision, moved on year
  by year while it is more than eleven months behind;
- `rentAt(Lease $lease, string $newIndex)` - the rent (charges aside) times the new index over
  the reference index, rounded to the cent; both indices are needed;
- `apply(Lease $lease, string $newQuarter, string $newIndex, \DateTimeImmutable $on)` - the new
  rent, the new reference (quarter and value), `revisedOn`, an activity line and the tenants'
  letter (`rent_revision` e-mail); returns the former rent;
- `due(int $days = 45)` - the active leases with a reference index whose revision falls within
  that many days, for the dashboard.

The back office's revision page takes the quarter ("T2 2025") and the INSEE value, shows the new
rent, then applies it from the anniversary - never backdated: from today when asked later.

## The books

With omnibase/ledger, and an organisation whose `bookId` names a book, `Base\Estate\Service\Books`
posts:

| Event | Entry (journal) | Source |
|---|---|---|
| a rent called | `VT`: the tenant's account (411, party `lease:<reference>`) debited the total; 706 credited the rent, 708 the charges | `estate:rent:<call reference>` |
| a payment | `BQ`: 512 (5112 for a card or SEPA payment, until the provider's payout) debited; the tenant credited | `estate:payment:<id>` |
| a work order closed with an invoice | `AC`: 615 debited; the contractor's account (401, party `contractor:<id>`) credited | `estate:work:<id>` |

A call paid in full has its entry and its payments' lettered on the tenant's account. Each
posting carries its source: nothing is posted twice.

`Base\Estate\Ledger\BankSettlement` listens to omnibase/ledger's `BankLineReconciled`: money in
whose settled open items come from rent calls (`estate:rent:<reference>`) pays those calls
(`Payments::record(..., 'bank:<line id>:<call id>', notify: true, post: false)`), the receipt sent,
nothing posted twice. `Base\Estate\Ledger\BankUnsettlement` listens to `BankLineUnreconciled`: the line
taken back in the books, the payments recorded from it (`bank:<line id>:`) are cancelled.
