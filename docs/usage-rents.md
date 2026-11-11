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

- `catchUp(int $days = 15, ?\DateTimeImmutable $now = null)` - the online payments started and
  not seen paid, read again from the provider: those paid since are recorded, those started more
  than `$days` ago given up. `start()` keeps the session on the call (`RentCall::getOnlineReference()`,
  `getOnlineStartedAt()`); a payment recorded clears it. `estate:payment:check [--days=15]` runs it:
  schedule it every 15 minutes (the site's crontab), and no webhook is needed - in development
  either, where the provider cannot reach the site.

`POST /estate/payments/webhook` (`estate_payment_webhook`, public) takes it: a rent call not paid
yet is recorded through `complete()`, under the session's reference - the tenant's return records
nothing twice. 204; 400 for a signature that does not match; 404 without a gateway. The rent is
paid even when the tenant never came back: a page closed, a SEPA debit through days later - the
webhook only records it sooner than `estate:payment:check` does.

**Nobody registers the webhook by hand.** `estate:payment:webhook [--force]`
(`Service\PaymentWebhook::ensure()`), run at each deploy, registers the endpoint at the provider
on the address the router builds for `estate_payment_webhook` outside a request (omnibase's
`base.router` hosts, `HTTP_DOMAIN`..., or `framework.router.default_uri`), through omnitrade's
`RegisterWebhook` (Stripe: the four `checkout.session.*` events, in the gateway's `api_version`).
The secret the provider answers is kept in omnibase's settings, sealed by its `#[Vault]` as
`/admin/api-key` keeps the API keys: `api.webhook.estate_payment_webhook.<gateway>.secret`, with
`.id` and `.url` beside it, no table of its own. `paidBy()` checks a webhook with that secret
(`Registry::create($gateway, ['webhook_secret' => ...])` over the configured options). The run is
idempotent:

| Answer | When |
|---|---|
| registered | nothing kept, the address changed, the endpoint gone at the provider, or `--force` (the provider replaces the endpoint on the same address: a new secret) |
| kept | the endpoint kept here is at the provider on this address: nothing done |
| not reachable | the address is `localhost`, `*.local`, `*.test`, a private IP or plain HTTP: nothing asked, a sentence says that `estate:payment:check` covers it - a development machine |
| configured | a `webhook_secret` is configured (`STRIPE_WEBHOOK_SECRET`): it wins, nothing registered, the endpoint it belongs to left alone |
| cannot seal | the environment has no vault key pair (`bin/console secrets:generate-keys`, or `SYMFONY_DECRYPTION_SECRET` on the server): no secret asked for, never one kept in clear |
| unsupported, off | the gateway cannot register its webhook, or no gateway |

Two runs at once (several web containers starting) take turns over the site's lock store
(symfony/lock, `LOCK_DSN`: shared between the hosts, or each could replace the other's endpoint).
The secret is sealed with the environment's vault key pair: `php bin/console secrets:generate-keys
--env=prod` once per environment (the private key never committed; a server can be given it as
`SYMFONY_DECRYPTION_SECRET`). It exits 0 in every case, so a deploy never stops on it. A new site gets the webhook with no
command typed: its deploy runs `estate:payment:webhook` after the cache is warmed (meyers: its web
entrypoint), its public address comes from its hosts, and the Stripe secret key is all it sets.

The tenant's "Payer" is a POST with the token `rent_pay_<id>`; refused for a paid call or when
online payment is off.

## The IRL revision

`Base\Estate\Service\RentRevisions` (loi du 6 juillet 1989, art. 17-1):

- `nextOn(Lease $lease, ?$today)` - a year after the start or the last revision, moved on year
  by year while it is more than eleven months behind;
- `quarters(Lease $lease, $on)` - the quarter a revision on `$on` is measured at (the lease's
  reference quarter, the years after: "T2 2025" → "T2 2026") and the one it is measured from: the
  lease's, or - a year whose revision was not claimed within the year being lost (art. 17-1, I) -
  the year before the new quarter's;
- `rentAt(Lease $lease, string $newIndex, ?string $newQuarter, ?string $fromIndex)` - the rent
  (charges aside) times the new index over the reference index (`$fromIndex`, else the lease's),
  the change capped where the law capped it for the new quarter (`Model\IndexCaps`), rounded to the
  cent;
- `propose(Lease $lease, ?$today)` - a `Model\RevisionProposal`: the anniversary, the quarters,
  and with the official index read the new value (`Model\IndexValue`: value, source's credit and
  licence, date in the Journal officiel) and the new rent; its `status` says what waits:
  `ready`, `waiting` (the quarter is not published yet: the revision waits), `unavailable` (INSEE
  did not answer: typed by hand), `manual` (no source installed), `no_reference`. Nothing is saved;
- `apply(Lease $lease, string $newQuarter, string $newIndex, \DateTimeImmutable $on, ?User $by, ?string $fromIndex)` -
  the new rent, the new reference (quarter and value), `revisedOn`, an activity line and the
  tenants' letter (`rent_revision` e-mail); returns the former rent. Only a person calls it, from
  the revision page;
- `due(int $days = 45)` - the active leases with a reference index whose revision falls within
  that many days, for the dashboard; `proposals($days)` - their proposals.

The back office's revision page takes the quarter ("T2 2025") and the INSEE value, shows the new
rent, then applies it from the anniversary - never backdated: from today when asked later. With
omnistate's statistics both come filled from INSEE, the source's credit and licence beside them
(« Insee, BDM, série 001515333, extraction au … — Licence Ouverte 2.0 »); the value can still be
typed over, and nothing is applied without the button.

### The official index (omnistate)

With `omnisense/omnistate` and `omnistate/insee`, `Index\OfficialIndex::value('IRL', 'T2 2026')`
asks INSEE's BDM for that quarter (`series('insee:IRL', $quarter, $quarter)`): its answers are kept
in omnistate's cache pool (`omnistate.cache`, `omnistate.ttl`: a day), within INSEE's 30 calls a
minute. Only the published value comes from there: the quarter, the anniversary, the caps and the
rounding are estate's. An error of the service is an `Exception\IndexUnavailableException`, which
the proposal turns into `unavailable`: the screen never fails on it.

`estate:rent:revisions` lists the revisions due within `--days` (45) with their proposals and the
source's credit; `--mail` sends each organisation its list (`revisions_due` e-mail), each line
opening the revision page. It applies nothing.

The caps (`Model\IndexCaps`): the « bouclier loyer » (loi n° 2022-1158, art. 12, extended by loi
n° 2023-568) - a revision on an IRL of T3 2022 to T1 2024 moves by 3.5 % at most, 2.5 % in the
overseas departments (postcodes 971 to 976); INSEE's IRL of those quarters already moves by 3.5 %.
To confirm with a lawyer: Corsica's own cap (3.5 % is applied there), and the year lost when a
revision was not claimed (the change measured from the year before, no catch-up). Each rule estate codes, its source and
what remains to confirm: [Legal rules](legal.md).

### Commercial and professional leases

A lease is a home's (`LeaseKind::RESIDENTIAL`, the default), a commercial lease or a professional
one (`Lease::setKind()`, « Type de bail » on its screen). It follows the index it names
(`indexName`): the IRL for a home, the ILC or the ILAT, chosen on the lease, otherwise; and a
revision rule (`Enum\RevisionRule`, « Révision du loyer »):

| Rule | For | What `propose()` does |
|---|---|---|
| `yearly` | a home | the IRL's yearly revision above (art. 17-1: a year not claimed is lost) |
| `indexation` | a commercial or professional lease | its indexation clause: yearly on the anniversary, the same quarter a year later, measured from the reference with no year lost - the contract's rule, ready when INSEE has published |
| `triennial` | a commercial lease | Code de commerce, art. L145-38: the date it may be asked (three years after the entry or the last price), the reference; the quarter is the team's to choose - the article does not name it -, and the page then gives the ceiling the index's variation sets (`MANUAL`, note `triennial`) |
| `none` | any | nothing proposed (status `none`) |

The lease's screen refuses an index or a rule its kind does not allow (`Lease::validateTerms()`).
The ILC of a small or medium-sized business (`smallBusiness`, « Locataire PME », declared by the
team) was capped at 3.5 % a year for the quarters T2 2022 to T1 2024 (loi n° 2022-1158, art. 14):
`IndexCaps::limit('ILC', $quarter, $postcode, true)`, applied to a yearly change; a change of
several years across that period is left to the team (note `cap_span`), the law's « définitivement
acquis » being computed by no rule here. Not modelled: a revision asked under art. L145-39 when an
indexation clause moved the rent by more than a quarter, the valeur locative of art. L145-33, the
clause « tunnel » art. L145-38-1 now allows, a professional lease's own terms (its contract
decides).

The lease's page lists what to check (`Model\LeaseRules::warnings()`): a commercial lease made
since 26 May 2026 whose deposit is above a quarter's rent (art. L145-40, al. 2) - a warning, since
only the team knows whether the premises are of the kind art. L145-32-1 names.

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

Every line of these entries carries the home as an analytic dimension, `unit=<unit id>`
(`Books::unit()`), so the ledger gives each home's result: the income statement filtered on a
home or per home (`?by=unit`). `Base\Estate\Ledger\UnitDimensionLabeler` names the axis's values
with the units' names. What was posted before carried none: `estate:books:units` (once, after the
upgrade; run again, it does nothing) adds the unit to those lines (`Books::tagUnits()`, the
amounts untouched - omnibase/ledger lets an axis be added to a validated line). The bank lines'
own entries (the reconciliation's `bank:<id>`) carry no unit.

`Base\Estate\Ledger\BankSettlement` listens to omnibase/ledger's `BankLineReconciled`: money in
whose settled open items come from rent calls (`estate:rent:<reference>`) pays those calls
(`Payments::record(..., 'bank:<line id>:<call id>', notify: true, post: false)`), the receipt sent,
nothing posted twice. `Base\Estate\Ledger\BankUnsettlement` listens to `BankLineUnreconciled`: the line
taken back in the books, the payments recorded from it (`bank:<line id>:`) are cancelled.
