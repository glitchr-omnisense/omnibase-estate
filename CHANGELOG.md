# Changelog

## [Unreleased]

### Added
- The payment provider's webhook registered by the application: `estate:payment:webhook [--force]`
  (`Service\PaymentWebhook::ensure()`), run at each deploy, registers `estate_payment_webhook` at
  the provider through omnitrade's `RegisterWebhook` and keeps the secret it answers sealed in
  omnibase's settings (`api.webhook.estate_payment_webhook.<gateway>.secret`, `#[Vault]`, no new
  table); `OnlinePayments::paidBy()` checks a webhook with it. Idempotent (kept when there), a
  configured `webhook_secret` wins, nothing asked on a host no provider reaches (localhost,
  `*.local`, `*.test`, a private IP), exit 0 always. `docs/usage-rents.md`.
- The dashboard's cards, omnibase/admin palette cards in its dashboard card (`Admin\Widget\Dashboard\*`):
  `estate_occupancy` (a donut of the homes let and vacant), `estate_rents` (rents called and collected
  month by month, 6 or 12 months switched in the card, legend, axis, tooltip, a table for screen
  readers), `estate_late`, `estate_works`, `estate_todo` (rows with an icon, a short text and an
  action button), compact ones two to a row on a phone; `estate_payments` is a palette card too,
  restyled. Their words in the `estate` domain (`dashboard.*`, French and English), their names in
  the `admin` domain (`estate_widget.*`). `Figures::months()`, `estate_once()`.
- The tests run in a host's container whose vendor is mounted elsewhere (`/srv/app/vendor`, or
  `ESTATE_AUTOLOAD`), as omnibase/ledger's.

### Fixed
- A rent paid online is recorded without any webhook: `start()` keeps the provider's session on
  the rent call (`onlineReference`, `onlineStartedAt`; a migration in the application), and
  `estate:payment:check` (`OnlinePayments::catchUp()`, `RentCallRepository::findOnlinePending()`),
  scheduled every 15 minutes, reads the open ones again and records those paid - a page closed, a
  SEPA debit through days later. A development machine, which no provider can reach, needs no
  tunnel nor `stripe listen`.
- A rent paid online is recorded even when the tenant never comes back to the site (a page
  closed, a SEPA debit through days later): `POST /estate/payments/webhook`
  (`PaymentWebhookController`, `OnlinePayments::paidBy()`), Stripe's paid Checkout sessions
  checked against `webhook_secret`. Only the return to the site recorded them.
- A bank line unreconciled in the books takes back the rent payment it recorded
  (`Ledger\BankUnsettlement`, on omnibase/ledger's `BankLineUnreconciled`; `Payments::cancel()`,
  `RentCall::removePayment()`): the rent call stayed paid, its receipt available, after the
  reconciliation was undone.
- Paying a rent online asks the provider again after a refusal, and for another method: the
  idempotency key was the call and the amount paid only, so Stripe, which keeps a key's answer for
  24 hours, refusals included, answered every later try with the first refusal, and a card session
  for a SEPA debit. It now names the method and the ten minutes the request falls in
  (`OnlinePayments::start()`, its new `$now`); a second click still opens the same page.
- The tenant's "Payer par carte" / "Prélèvement SEPA" form is posted with a full page load (class
  `reload`): flowjs' in-place navigation sent it in the background, could not follow the redirect
  to checkout.stripe.com, and the tenant stayed on the page with nothing happening.
- The info of `estate.reminder_days` names the command that reminds, `estate:rent:late`
  (`RentCalls::markLate()`); it named `estate:rent:remind`, which does not exist.
- A README, as every omnibase/* package has: what it is, its requirements, its installation and
  crons, the manual's pages.
