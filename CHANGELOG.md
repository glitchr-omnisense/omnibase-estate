# Changelog

## [Unreleased]

## [1.0.1] — 2026-11-14

### Changed
- The current names of what moved to omnibridge/symfony, in place of the former ones the core keeps as aliases only
  until 6.0: `Base\BaseBundle` → `Omnibridge\Symfony\Omnibase\BaseBundle`;
  `Base\Bundle\AbstractBaseBundle` → `Omnibridge\Symfony\Omnibase\Bundle\AbstractBaseBundle`;
  `Base\Bundle\AbstractBaseExtension` → `Omnibridge\Symfony\Omnibase\Bundle\AbstractBaseExtension`.
  No behaviour change; the bundle no longer needs any alias of the core's `former-names*.php`.

## [1.0.0] — 2026-11-13

First stable release.

### Changed
- Stable requirements, so that `composer require omnibase/estate` installs at Packagist's default stability:
  `omnisense/omnibase` ^5.1 (in place of `3.x || 4.0 || 5.0` dev branches), `omnibridge/symfony` ^1.1, `omnibase/admin` ^1.0, `doctrine/doctrine-bundle` ^2.10 || ^3.0 (its repositories extend DoctrineBundle's `ServiceEntityRepository`);
  no `minimum-stability: dev`; the branch alias `dev-main` → `1.x-dev`.
- CI: `.github/workflows/ci.yml` (glitchr-studio/ci's php-package workflow, pinned: the bundle installed alone,
  composer validate, PHPUnit, a consumer install without require-dev with `src/` linted and the bundle class
  autoloaded) and `release.yml` (that CI on a tag, then the release).
- require-dev: PHPUnit and what the tests read (omnibase/ledger, omnibase/drive, omnibridge/doctrine, omnisense/omnisign,
  omnistate/insee, omnitrade/stripe, dompdf, Twig with its intl extension), so that the suite runs outside a site.
- Neutral example data in docs and tests.

### Changed
- The guarantor's mention on omnisense/omnisign 1.2's statements: each field is a statement the guarantor types
  (`Field::forStatement()`, `StatementMode::TYPED`), in place of a bare text field with a label. The whole mention goes
  in one field where the provider's documented limit takes it (`Capabilities::fits()`), one field a sentence otherwise
  (`SuretySignatures::mentions()`), which was the only layout before; a provider that cannot take a sentence typed is
  named among what the deed is missing (`missing()`), so the deed is never sent to fail at the provider. Needs
  omnisense/omnisign 1.2 and its providers of that version.
- The dashboard's cards scale with the number of organisations: each figure is one query for every organisation
  together - grouped or summed in the database, scalars rather than entities -, and only the rows a card lists are
  loaded (four late rents and four of the month's, five homes, four work orders), with their lease, home and parties.
  About 23 queries per organisation before (2,386 for 100 companies of a site's DashboardScaleTest), the same 100 for 10
  and for 100 now; what the cards show is unchanged. `Service\PortfolioFigures` (`homes()`, `months()`), repository
  totals (`RentCallRepository::overdueTotals()`, `findOverdueFirst()`, `overdueByOrganisation()`, `monthTotals()`,
  `findInMonthNotOverdue()`; `UnitRepository::homes()`, `findWithLeases()`; `WorkOrderRepository::openTotals()`,
  `findOpenFirst()`, `spentBy()`; `ApplicationRepository::countNewByOrganisation()`;
  `MessageRepository::unreadByOrganisation()`; `LeaseRepository::findActive()`, `findUninsured()`), and
  `PaymentRepository` (`totalsByMethod()`). `RentRevisions::due()` loads the active leases with their home and parties.
  The late, works and occupancy templates read the counts (`lateCount`, `openCount`, `lettable`) instead of the length
  of a full list. No schema change.

### Added
- The guarantor's deed (acte de cautionnement) signed through omnisign (Plan 0, chantier I): `Signature\SuretySignatures`
  generates one deed per guarantor (`@Estate/pdf/surety.html.twig`: the parties, the lease, the rent and its revision terms
  as the lease states them, the commitment's limit in words and figures, its duration or none, joint or not, for a home's
  lease art. 22-1's paragraph reproduced) and sends it in its own envelope about the guarantor, with a copy of the lease to
  read and a signature page where the guarantor types art. 2297's mention; followed by the webhook, the signed deed filed
  (`DocumentKind::GUARANTEE`, not the tenants'), the team e-mailed (`surety_signature`). `LeaseParty::$suretyLimit`,
  `$suretyUntil`, `$suretyJoint`, `$address` (`Form\SuretyTermsType`); the deeds' page `estate_admin_surety`, a
  « Cautionnement » button and each deed's state on the lease's page; `signature.surety_template`. The template is a starting
  point to be validated by a lawyer: said on the page until the owner confirms it (`Organisation::$suretyTemplateConfirmedAt`,
  « Modèle d'acte de cautionnement validé par un avocat », ROLE_SUPERADMIN). Texts and what remains to confirm:
  docs/legal.md. Needs a migration. tests/Signature/SuretySignaturesTest.
- VAT on rents, where the landlord opted for it: `Lease::$taxCode` (a VAT code of the company's book) and `taxCharges`;
  each rent call made fixes its VAT at the code's rate (`Books::vatOf()`, `RentCall::$vat`, `$taxCode`; `getTotal()`
  includes it) and is posted 706 / 708 before tax and 44571 with its base (`EntryDraft::taxed()`), only when the book is
  liable and the code collects VAT; never on a managed home's rent. The notice, its e-mail and the receipt show the rent
  before tax and the VAT; a commercial tenant's receipt is the same quittance, « du local ». The lease's page says whether
  VAT is called and why not. Needs a migration (`estate_lease.taxCode`, `taxCharges`; `estate_rent_call.vat`, `taxCode`).
  tests/Service/BooksTest.
- Commercial and professional leases (Plan 0, chantier I): `Lease::$kind` (`Enum\LeaseKind`: residential, the default,
  commercial, professional), the index it follows (`indexName`: IRL, ILC or ILAT), its revision rule
  (`Enum\RevisionRule`: yearly, indexation, triennial, none) and whether its tenant is a small business. `RentRevisions::propose()`
  reads the lease's index from INSEE; an indexation clause is proposed yearly with no year lost, a triennial revision
  (Code de commerce, art. L145-38) gives its date and the ceiling on the quarter the team chooses. `Model\IndexCaps` caps
  the ILC of a small business at 3.5 % for T2 2022 to T1 2024 (loi n° 2022-1158, art. 14) and leaves a change across that
  period to the team. `Model\LeaseRules::warnings()` on the lease's page: a commercial lease's deposit above a quarter's rent
  (art. L145-40). The generated lease, the revision page and its e-mails follow the lease's kind and index.
  `docs/legal.md` lists every rule with its source. Needs a migration (four columns on `estate_lease`, defaults keeping every
  lease as it was). tests/Service/CommercialRevisionsTest on INSEE's ILC and ILAT recorded on 2026-11-11.

### Fixed
- The back office's screens write through omnibase/admin's hooks on the persistence the site chose: Lease, LeaseParty,
  Mandate, MandateExpense, Mandator, Ownership, OwnershipCall, OwnershipShare and SaleMandate override `persistRecord()`,
  `updateRecord()` and `removeRecord()` (the admin's step B1a, a `Omnibridge\Persistence\UnitOfWork`) in place of the
  former `persistEntity()`, `updateEntity()` and `removeEntity()` on Doctrine's entity manager, deprecated there. They do
  what they did: a mandate, a sale mandate or a call numbered, a lease's and a flatmate's folders and group, an expense
  posted, a share completed and checked. Needs omnibase/admin from its step B1a, which every site on omnisense/omnibase 5.x
  has. tests/Controller/Admin/RecordHooksTest.
- `Service\Invitations` finds what an invitation opens and flushes its acceptance through the core's unit of work and
  repositories (`UnitOfWorks::repositories()`, `markAccepted()`): omnisense/omnibase 5.x's `Base\Service\Invitations`
  no longer keeps `$entityManager`, so /invitation/{token} answered 500 ("Call to a member function find() on null").
  A core without the unit of work (4.x before its step F2d) keeps the entity manager it was given, as before.
  tests/Service/InvitationsTest.

### Changed
- Accepts omnisense/omnibase 5.0 (`3.x-dev || 4.0.*-dev || 5.0.*-dev`; plan "omnibase agnostic", step S): the bundle runs
  unchanged on it, `Base\Bundle\AbstractBaseBundle` and the other former names it uses being aliases of
  omnibridge/symfony's classes there. The sites on 4.x resolve as before.
- `estate_summary` (SummaryWidgetType): the dashboard's figures read first in one band across it -
  the homes let, the month's rents received over called, what is late, the monthly rent roll, the
  works in progress and the year's cost -, each opening its list. Occupation and Loyers en retard
  given half the page put the donut beside its legend and each home or rent on one line, two to a
  row when wider still.
- The dashboard's Occupation card lists each home and where it stands, the vacant first (since when,
  or never let), then a lease ending (notice given, or its end within three months), then the let
  ones with their tenants, each with a state in words; the donut's figure keeps inside its ring. The
  Loyers en retard card also says how the month goes: its rents received over called, then each one
  paid, partly paid or due (the current month once called, the last one before); with nothing late
  its subtitle reads « tout est à jour », not « aucun loyer » beside « 0 € dus ».

### Added
- The lease and its inventory of fixtures signed electronically, with omnisense/omnisign (optional,
  `Signature\LeaseSignatures`, loaded only with it and the core's `Base\Service\Signatures`): the
  lease uploaded by the team, else its particular conditions generated (`@Estate/pdf/lease.html.twig`),
  or the inventory uploaded, each with a signature page naming it by its SHA-256 and one box per
  signer; signed in order by the landlord, the tenants, then the guarantors (the lease); followed
  through the provider's webhook (the core's `POST /signatures/{gateway}/webhook`): completed, the
  signed PDF filed as the lease's document (« (signé) », seen by its tenants), the team e-mailed
  (`lease_signature`); declined or expired, the team told. `estate.signature` (`gateway`,
  `template`, `inventory_template`, `level`); the back office's page `estate_admin_signature`
  (state, signers, send, refresh, remind, cancel, the signed PDF and its evidence) and the state on
  the lease's page. Not the notary's authentic act; the guarantee's own deed is not sent yet.
  docs/usage-signature.md.
- The rent revision read from the official index, with omnisense/omnistate's statistics and
  omnistate/insee (optional, `Index\OfficialIndex`, loaded only with them): `RentRevisions::propose()`
  gives a lease's anniversary, its reference quarter and value, the new quarter, INSEE's value with
  its credit and licence, and the new rent by `rentAt()` - or why it waits: the quarter not yet
  published, INSEE silent (typed by hand, as without omnistate), no reference. `quarters()`: a year
  not claimed within the year is lost (art. 17-1), the change then measured from the year before;
  `Model\IndexCaps`: the « bouclier loyer » caps (3.5 %, 2.5 % overseas) for the IRL of T3 2022 to
  T1 2024. The revision page comes filled, the source credited beside the figure; nothing is
  applied without the button. `estate:rent:revisions` (`--days`, `--mail`: the `revisions_due`
  e-mail) proposes the revisions due and applies none. `rentAt()` and `apply()` take the new quarter
  and the base index (optional, last). Tests on the BDM's answer recorded on 2026-11-10
  (tests/Fixtures/insee-irl.xml), no live call.
- A home's plan for its tenants, private: `Entity\Room` (`estate_room`: name, `Enum\RoomKind`,
  level, a polygon in centimetres on a 10 cm grid, a measured surface that prevails, counted in the
  living area or not), `Unit::getPlanRooms()`, `getLivingArea()` (`Unit::$surface` stays the
  declared surface; `getSurfaceGap()` shown to the team) and an architect's plan to trace over
  (`planBackground`, its scale and offset; a PDF's first page with Imagick). The back office's
  editor (`Controller\Admin\PlanController`, `public/js/plan-editor.js`: rectangles, corners, merge
  into an L and split on the grid's cells, levels, undo; saved as JSON by `Service\Plans`), « Plan »
  on the homes' screen. `EstateVoter::UNIT_PLAN` (the tenants of the current lease, the associates,
  co-owners and owners, the team); the plan in `/locataire`, at `/plan/{id}` and as an A4 sheet
  `/plan/{id}/imprimer`. Never public (Marco, 2026-11-01: « les plans sont pour les locataires,
  c'est privé »): no option shows it on the listing, a visitor or another tenant gets a 404.
  `Model\FloorPlan`, `Model\Polygon`, Twig `estate_plan()` and `|m2`.
- The homes' works told: `Entity\StoryEntry` (`estate_story_entry`: date, title, text,
  `Enum\StoryState` in progress or done, started on, planned end, an amount shown only when ticked,
  its work orders in `estate_story_entry_work_order`) and `Entity\StoryPhoto` (`estate_story_photo`:
  before, during or after, the view that pairs a before with an after, « OK pour publier », private
  by default). `Service\Stories`: an entry from a work order (« Raconter »; its photos copied,
  private), more work orders linked, the state they suggest, publishing refused while a tenant's
  name (or the mark left where one was taken out) is in it. A works photo's `Document::$phase` and
  `$view`, picked by the contractor in `/artisan` and by the team. Public only on `/travaux`
  (`estate_works`): the works under way first, then the works done with an accessible before/after
  slider (`public/js/before-after.js`, `public/css/before-after.css`: plain images without
  JavaScript, the keyboard, no sweep under reduced motion), its intro `@estate.works.lead`; a home's
  page shows no story, no works, no before/after (Marco, 2026-11-01).
- Ready-to-post images for the networks (`Service\StoryImages`, Imagine): each cleared pair side by
  side and as a two-slide carousel, in 1080×1080, 1080×1350 and 1200×630, with the title, the place
  and the company's mark (`estate.story.font`, `font_regular`, `mark`, `color`), their zip with the
  suggested text; the text per network and the link to `/travaux` with UTM parameters. Posting
  directly is left to omnibase/social (docs/usage-plans-stories.md says what it would take).
- A lease with two occupants or more is a flatshare (a couple too), with omnibase/drive's groups:
  `Drive\FlatshareFolders` gives a signed one a group « Colocation <home> » (`ShareGroup`) of its
  occupants who have an account, kept as they are added, deleted or accept their invitation, and a
  common folder `Colocations/<home>`, the team's, shared read only with the group (the lease, the
  inventory, the house rules); each keeps their own `Locataires/<name>`. An ended lease's group loses
  its members, its folder stays; a later lease of the same home gets `Colocations/<home> -
  <reference>`. `Lease::$folder` and `Lease::$groupId` (columns `estate_lease.folder`,
  `estate_lease.groupId`, a migration in the site), `Lease::isShared()`. `LeaseFolders::sync()`
  makes it (its new optional `FlatshareFolders` argument); `LeasePartyCrudController::removeEntity()`
  syncs the lease of a party deleted.
- A signed lease gives each of its tenants a cloud folder (with omnibase/drive), as a management
  mandate gives its owner one: `Drive\LeaseFolders`, `Locataires/<name>` for each tenant and
  co-tenant (not the guarantors) of a lease running or under notice, the team's, shared by name
  read only once they have an account, a folder already there reused with what was said of it;
  `LeaseParty::$folder` (column `estate_lease_party.folder`, a migration in the site),
  `LeaseParty::isOccupant()`. Made when a lease or a party is saved in the back office and when a
  tenant accepts their invitation (`Invitations` takes `LeaseFolders` and a logger, both optional).
- `Drive\EstateSpaces`, omnibase/drive's `SpaceProviderInterface`: a person's space for files the
  team shares on upload - the tenant's folder, else the owner's (management), else the ownership's
  (a co-owner) -, made if missing.

### Changed
- The withdrawal period counts the property's place's public holidays, by the mandate's postcode
  (Marco, 2026-11-01): `Model\PublicHolidays` (the nation's, plus Alsace-Moselle's Good Friday and
  26 December, the overseas days of the abolition of slavery, Saint-Barthélemy's and Saint-Martin's
  by their postcodes), `WithdrawalPeriod::end($notifiedOn, ?string $postcode)` in place of its
  `alsaceMoselle` flag, `estate_local_holidays()`; the admin's sale says when they were counted.

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
