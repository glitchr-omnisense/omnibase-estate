# Changelog

## [Unreleased]

### Changed
- The owners' screen (« Propriétaires », `MandatorCrudController`) serves management and
  transaction: its management actions and folder only while the site runs management.
- `Model\OwnershipPeriod` is `Model\Period`: the statements' periods, the co-owners' and the
  owners'.

### Added
- Sales for third parties, the `transaction` activity: the seller is an owner (`Entity\Mandator`,
  shared with management: one record, one account), `Entity\SaleMandate` (its sellers -
  `estate_sale_mandate_seller` -, the agency, one of the homes or a property described in place,
  the asking price, simple or exclusive, from / until, the fee - `Enum\FeeBasis`, a percentage of
  the price or a fixed amount, all taxes included -, `Enum\FeePayer`, its VAT code, `V20` by
  default, who holds the deposit; numbered `VT-<year>-<n>`), `Entity\Buyer` (what they look for,
  `Enum\Financing`), `Entity\Visit`, `Entity\Offer` (`Enum\OfferStatus`: made, countered,
  accepted, refused, withdrawn), `Entity\Sale` and its steps (`Enum\SaleStep`: offer accepted,
  compromis signed, withdrawal period over, conditions lifted, acte signed, closed, or cancelled
  with its reason), each dated with its documents (`Entity\SaleEvent`), moved one at a time by
  `Service\Transactions::advance()` (a step skipped refused); the withdrawal period's end
  (`Model\WithdrawalPeriod`: ten days from the day after the notification, the next working day,
  the French public holidays with Easter's, Alsace-Moselle's on demand). In the agency's book
  (`Ledger\TransactionBooks`, loi Hoguet): nothing before the acte; at the acte the fee invoiced
  (`FH-<year>-<n>`) to the seller or the buyer, 411 (omnibase/ledger's `PartyKind::CUSTOMER`) /
  7062 + 44571 when the book is liable; the deposit recorded, posted only when the agency holds it
  (escrow account / 467 the buyer, out at the acte or the cancellation); every line marked
  `transaction=<id>` (`Ledger\TransactionDimensionLabeler`) and `unit=<id>`. A buyer matched
  against the mandates (`Transactions::matches()`). The seller's space `/vendeur` and the buyer's
  `/acquereur` (`Controller\Client\TransactionController`, `Spaces::sellers()`, `saleMandates()`,
  `buyers()`, `seller` and `buyer` in `Spaces::of()`, `EstateVoter::SALE_VIEW`); with
  omnibase/drive, optional, the folder `Transactions/<number>` shared read-only with the sellers
  and the accepted offer's buyer, each step's document in it (`Drive\SaleFolders`). The back
  office: sale mandates, buyers, visits, offers, a mandate's page (its visits, offers - accept,
  counter, refuse, withdrawn -, the sale's steps and the next one's form, cancel), the sales page,
  a buyer's matches, the dashboard card `estate_transaction`; all only with the activity
  (`ActivityMenu`; the owners' screen with management or transaction). Seven tables (a migration
  in the application). `docs/usage-transaction.md`.
- The Twig filter `estate_folder`: a cloud folder's link through omnibase/drive's `drive_slug` when
  the site has it.
- Property management for third parties, the `management` activity: `Entity\Mandator` (an
  owner, a person or a company, their IBAN and BIC, their account, their cloud folder),
  `Entity\Mandate` (the owner, the agency, the homes - `estate_mandate_unit`, nothing changes on
  the home -, the fee in basis points before tax and its VAT code, `V20` by default, from / until,
  `Enum\StatementFrequency`, the reserve; numbered `MG-<year>-<n>`). In the agency's book, the
  owners' money apart (loi Hoguet, `Ledger\ManagementBooks`): a managed home's rent called on 411 /
  4672 (no 706), collected to the owner's account 4672 / 4671 (`PartyKind::MANDATOR`), its cash on
  the escrow account (omnibase/ledger's `BankAccount::$escrow`, 512 otherwise); the agency's fee
  on each rent collected (`Entity\MandateFee`, kept as charged) 4671 / 7061 + 44571 when the book
  is liable; a work order's invoice charged to the owner when it is closed, or an expense entered
  by hand (`Entity\MandateExpense`, 4671 / 401); every line marked `owner=<id>` and `unit=<id>`
  (`Ledger\OwnerDimensionLabeler`). `Payments::record()` and `cancel()`, `Books` and
  `WorkOrders::close()` hand a managed home to `Service\Management`; `estate:management:income`
  for the rents collected before a mandate was entered. The owner's statement (compte rendu de
  gérance, `Model\MandateStatement`: opening balance, each home's rents called and collected, fees
  and VAT, expenses, payouts, closing balance, what a payout would be, what the tenants still
  owe), its PDF and e-mail, filed in the owner's folder; the payouts (`Entity\MandatePayout`,
  `Enum\PayoutStatus`, `RV-<year>-<n>`): what each owner is due at a day less the reserve, issued
  (4671 / 4678), a SEPA credit transfer file pain.001.001.03 from the escrow account through
  omnibank/files' `Pain001Writer` (optional), paid by hand (4678 / escrow) or by the bank line
  the reconciliation matches by their reference (`Ledger\ManagementSettlement` on
  `BankLineReconciled`). The owner's space `/proprietaire` (`Controller\Client\OwnerController`,
  `Spaces::mandators()`, `owner` in `Spaces::of()`, `EstateVoter::MANDATE_VIEW`); with
  omnibase/drive, optional, the folder `Propriétaires/<name>` shared with the owner read-only
  (`Drive\MandatorFolders`). The back office: owners, mandates, expenses, payouts, the statements
  page (each one's PDF, send one or all, those to send), the payouts page (issue, SEPA file, paid,
  cancel), the dashboard card `estate_management`; all of it only with the activity
  (`ActivityMenu`). Six tables (a migration in the application). `docs/usage-management.md`.
- `RentCallRepository::forUnits()`.
- Shared ownership, the `ownership` activity: `Entity\Ownership` (an indivision, its homes, its
  managing organisation, its cloud folder), `Entity\OwnershipShare` (a co-owner - a person, an
  associate or the company itself -, an exact fraction, an IBAN, from `startsOn` until `endsOn`),
  checked to add up to one on every date they change (`Ownership::problems()`, `Model\Shares`);
  `Entity\OwnershipInvoice` divided by the shares in force or by hand (`Enum\SplitMode`) and
  posted by `Service\Ownerships::post()`: the expense with its VAT when the book is liable, the
  supplier, a call for funds per co-owner (`Entity\OwnershipCall`, `AF-<year>-<n>`, e-mailed)
  on their 467 current account against 7551, every line marked `ownership=<id>` and `unit=<id>`
  (`Ledger\OwnershipBooks`, `Ledger\OwnershipDimensionLabeler`); the rents of its homes shared
  out when received (6551 / 467, `Payments::record()` → `Ownerships::rentReceived()`, reversed by
  `Payments::cancel()`, `estate:ownership:income` for those received before); payments by hand
  or by the bank reconciliation (`Ledger\OwnershipSettlement` on `BankLineReconciled`,
  `Entity\OwnershipPayment`). The co-owner's statement for a month, a quarter or a year
  (`Model\OwnershipStatement`, `Model\Period`), its PDF and e-mail; the co-owner's space
  `/indivision` (`Controller\Client\CoOwnerController`, `Spaces::coOwnerships()`, `coowner` in
  `Spaces::of()`, `EstateVoter::OWNERSHIP_VIEW`); with omnibase/drive, optional, the folder
  `Indivisions/<name>` shared with the co-owners by name, deposit allowed, the invoices posted in
  its « Factures » (`Drive\OwnershipFolders`). The back office: indivisions, shares (the sum
  said on saving), shared invoices and their split preview, calls (send, collect), the
  statements page, the dashboard card `estate_ownership`; all of it only with the activity
  (`ActivityMenu`). Six tables (a migration in the application). `docs/usage-ownership.md`.
- `Receipts::htmlToPdf()`: any printable page of the bundle as a PDF; the PDFs write French
  amounts' narrow no-break spaces and minus signs as Helvetica has them, not as « ? ».
- The activities a site runs, combinable: `estate.activities` (`Enum\Activity`: `assets`,
  `ownership`, `management`, `transaction`; `[assets]` by default, so every site stays as it
  was), `Service\Activities` (`has()`, `any()`, `all()`), the Twig functions
  `estate_activity()` and `estate_activities()`. `Admin\ActivityMenu::filter()` leaves out of a
  dashboard's items the menu items and cards of an activity the site does not run - tenants,
  leases, rents, works and contractors are assets', ownership's and management's, the
  associates assets' -, labels, order and keys untouched, a section left empty left out.
  `docs/configuration.md`, `docs/back-office.md`.
- Estate's entries carry the home as an analytic dimension on every line (`unit=<id>`): rent
  calls, payments, work invoices (`Books::unit()`); `Ledger\UnitDimensionLabeler` names the
  ledger's `unit` axis with the units' names; `estate:books:units` adds the unit to what was
  posted before (`Books::tagUnits()`). `docs/usage-rents.md`.

### Changed
- The associates' feed writer is `Service\ActivityLog` (was `Service\Activities`, now the
  activities' switch); its `log()` is unchanged.

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
- The rent calls' list fits a laptop's width: the reference moves to the detail page (the lease and
  the due date say which call it is), and « Reçu », « État » and « Quittance » no longer sit under
  the pinned actions.
- The dashboard's « Encaissements » lists the 4 latest payments, not 6, so the card stands level
  with « Comptabilité » beside it.
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
