# Property management for third parties (gérance)

The `management` activity (`estate.activities: [..., management]`): an agency manages homes it
does not own, for their owners. Nothing of it shows, and its tables are not read, while the site
does not run the activity.

- **The owner** (`Entity\Mandator`, the mandate's *mandant*): a person or a company (`company`,
  its SIREN), how to reach them, the IBAN (and BIC, optional in SEPA) their payouts go to, their
  account - it opens the owner's space -, their cloud folder. Named after the mandate's legal
  party: "owner" alone says nothing of the agency's side, and `Ownership` is the shared ownership
  already.
- **The mandate** (`Entity\Mandate`, *mandat de gestion*): its owner, the agency (the
  organisation whose books carry the owner's account), its homes, the fee on the rents collected
  (`feeRate`, basis points before tax: 700 is 7 %; `setFeePercent(7)`), the fee's VAT code in the
  agency's book (`V20` by default), its first and last days (`startsOn`, `endsOn`, both included),
  how often the statement and the payout go (`Enum\StatementFrequency`: monthly by default,
  quarterly, yearly), the reserve kept on the owner's account at each payout. Numbered in the
  register of mandates when saved (`MG-2026-0001`, `Management::number()`).
- **A home under a mandate is its owner's.** The homes, leases and rent calls of estate stay as
  they are: the mandate names its homes (`estate_mandate_unit`), nothing changes on the home, and
  a home's mandates over time are kept. `Management::mandateFor($unit, $day)` gives the mandate a
  home is managed under that day (`MandateRepository::findForUnit()`).
- **The fee** (`Entity\MandateFee`): charged on each rent collected, kept as charged (base, rate,
  before tax, VAT, code), so a rate changed later leaves the past statements as they were.
- **The expenses** (`Entity\MandateExpense`): a work order's invoice on one of the owner's homes
  (made when the work order is closed, once per work order), or one entered by hand - the
  syndic's call, an insurance, the property tax paid for them; all taxes included, its file.
- **The payouts** (`Entity\MandatePayout`, `Enum\PayoutStatus`): issued, in a SEPA file, paid.
- **The owner's statement** (`Model\MandateStatement`, *compte rendu de gérance*), its PDF and
  e-mail; **the owner's space** (`/proprietaire`) and **their cloud folder** with omnibase/drive.

## The service

```php
use Base\Estate\Service\Management;

$management->mandateFor($unit, $day);              // the mandate a home is under, null while the activity is off
$management->rentReceived($payment);               // the owner credited, the fee charged (Payments::record() calls it)
$management->workInvoiced($order);                 // the invoice charged to the owner (WorkOrders::close() calls it)
$management->postExpense($expense);                // an expense entered by hand, posted once
$statement = $management->statement($mandate, $from, $to);
$management->statementPdf($statement);             // dompdf, as the receipts
$management->sendStatement($statement);            // e-mailed, the PDF attached and filed in their folder
$management->payoutsDue($on);                      // what each owner is due at $on
$payout = $management->issuePayout($mandate, $on); // RV-2026-0001, taken off their account
$xml = $management->sepa([$payout], $executionDay);// pain.001.001.03 (omnibank/files)
$management->markPaid($payout, $paidOn, 'VIR ...');
$management->syncFolder($mandator);                // Propriétaires/<name>, shared with them read-only
```

The fee is the mandate's rate of the amount collected, before tax, rounded to the cent half up
(7 % of 450,00 € is 31,50 €); its VAT is the book's code (`Tax::split()`), added only when the
agency's book is liable (`Book::$vatRegime`).

## The books: the agency's, the owners' money apart (loi Hoguet)

A property manager holds money for others: the rents it collects are its owners', not its income
(loi Hoguet n° 70-9 du 2 janvier 1970 and its décret n° 72-678: a professional card « gestion
immobilière », a financial guarantee for the funds held, a separate account for them, a register
of mandates, an account rendered to each owner). The French treatment follows:

- **the owners' money sits on a separate bank account** - the escrow account, *compte séquestre*
  or *compte mandant*. omnibase/ledger marks one of the book's bank accounts so
  (`BankAccount::$escrow`, « Compte séquestre » on its screen); the rents collected by hand for a
  managed home are posted to its 512x, the payouts leave from it (512 when the book has none);
- **each owner has their own account**, 4671 + their reference (a ledger `Party` of kind
  `mandator`, `PartyKind::MANDATOR`, e.g. `4671M5`): what the agency holds for them, a credit
  balance;
- **the agency's income is its fees** (7061 « Honoraires de gestion locative », with their VAT on
  44571), taken from the owner's account;
- every line carries the dimensions **`owner=<mandator id>`** (named by
  `Ledger\OwnerDimensionLabeler`) and **`unit=<id>`** when it is about one home: the general
  ledger filtered by owner is their account, the income statement by owner the agency's fees from
  them.

### The entries

| What | Journal | Debit | Credit | Source |
|---|---|---|---|---|
| A rent called for a managed home | VT | 411 the tenant | 4672 « mandants - loyers appelés à encaisser » | `estate:rent:<reference>`, piece = the rent's reference |
| The rent collected by hand | BQ | 512x the escrow account | 411 the tenant | `estate:payment:<id>` |
| The rent seen at the bank | BQ | 512x the escrow account | 411 the tenant (the reconciliation's entry, lettered) | ledger's |
| The rent, the owner's | OD | 4672 | 4671 the owner | `estate:management:rent:<payment id>` |
| The agency's fee on it | VT | 4671 the owner, all taxes included | 7061 before tax, 44571 its VAT (`EntryDraft::taxed()`) | `estate:management:fee:<id>` |
| An expense paid for the owner | AC | 4671 the owner, all taxes included | 401 the supplier | `estate:management:expense:<id>`, piece = the invoice's number |
| A payout issued | OD | 4671 the owner | 4678 « mandants - reversements à payer » | `estate:management:payout:<id>`, piece = `RV-2026-0001` |
| The payout paid by hand | BQ | 4678 | 512x the escrow account (lettered) | `estate:management:payout:<id>:paid` |
| The payout seen at the bank | BQ | 4678 | 512x (the reconciliation's entry, lettered) | ledger's |

- **The rent is the owner's when collected.** The tenant's debt stays on 411, so the bank
  reconciliation matches their transfer by the notice's reference as for any rent; called and
  unpaid, it waits on 4672 and is listed on the statement, not counted in the owner's balance.
  4672 is lettered once the call is paid. The money is posted the owner's way whoever recorded
  it: by hand, online, or a transfer reconciled (`Payments::record()` hands each payment to
  `Management::rentReceived()`; `Payments::cancel()` reverses the owner's credit and the fee).
- **The owner bears the VAT on their expenses** (they are not liable for a home let unfurnished):
  the amount all taxes included is charged, the agency deducts nothing of it.
- 4671's balance less the mandate's reserve is what a payout pays; 4672, 4678 and 7061 are created
  in the book when first needed.
- `estate:management:income` credits the owners with the rents collected before a mandate was
  entered (since its start) and charges the fees, each once.

### Payouts and the SEPA file

1. **What is due** at a period's end (`payoutsDue($on)`, `available()`): the owner's balance that
   day (`balance()`: the statement's closing balance), less the payouts already issued on it, less
   the mandate's reserve.
2. **Issued** (`issuePayout()`, « Émettre les reversements »): numbered `RV-<year>-<n>`, the
   owner's IBAN kept on it, posted 4671 / 4678 with its reference as the piece. Taken back before
   it is paid (`cancelPayout()`): its entry reversed, the balance back.
3. **The SEPA file** (`sepa()`): omnibank/files' `Pain001Writer` writes a pain.001.001.03 - the EPC
   implementation, service level SEPA, charges SLEV, euros: the agency (its legal name) as the
   debtor, the escrow account's IBAN as its account (the organisation's IBAN without one), one
   transfer per payout to the owner's IBAN (and BIC when known), the payout's reference as the
   end-to-end id - the bank's statement carries it back -, « Reversement de gérance RV-... au
   <date> » as the remittance information. The payouts are marked exported. Without
   omnibank/files, the page says to transfer them by hand. A file leaves from one account: one
   agency's payouts at a time.
4. **Paid**: the bank line comes on the escrow account's statement; omnibase/ledger's `Matcher`
   finds the payout's reference in it (0.99) against the open 4678 item, `Reconciler::apply()`
   posts and letters it, and `Ledger\ManagementSettlement` (on `BankLineReconciled`) marks the
   payout paid (`bank:<line>`), without posting it again - as `BankSettlement` does for rents.
   Or « Payé » by hand: 4678 / the escrow account.

The owner's account carries no IBAN in the books: a transfer to them is recognised by its
reference, never matched against their rents by the IBAN.

## The statement (compte rendu de gérance)

From the owner's side, what the agency holds for them is positive:

- **opening balance**: everything below, from the mandate's start until the day before the period;
- **each home's rents**: called in the period, and collected in it (the payments, one line each);
- **the fees**: before tax, their VAT, all taxes included;
- **the expenses** charged (posted ones);
- **the payouts** issued in the period;
- **closing balance** = opening + collected − fees − expenses − payouts, and **available** = the
  closing balance less the reserve;
- **still owed by the tenants**: the rents called by the period's end and not paid - listed, not
  in the balance.

Built from estate's records - the same the books are posted from - so it holds with or without
omnibase/ledger. A rent counts while the mandate runs. The PDF is
`@Estate/pdf/mandate_statement.html.twig`; the e-mail `@Estate/email/mandate_statement.html.twig`
attaches it, and the PDF lands in the owner's folder (« Comptes rendus »). Periods are
`Model\Period` keys (`2026-10`, `2026-Q3`, `2026`); `StatementFrequency::lastClosed($today)` is
the period a statement is due for. A statement sent marks its period on the mandate
(`Mandate::markStatementSent()`, `statementSentFor`): `statementsToSend()` lists the mandates whose
last closed period has not gone out.

## The owner's space

`/proprietaire` (`Controller\Client\OwnerController`, route `estate_owner`), offered by
`Spaces::of()` as `owner` to an account an owner (`Mandator::$user`) names: their account's balance,
the next payout, the year's rents collected and fees, their homes with each one's lease and tenant
and what was collected, what the tenants still owe, their payouts, their statements by period as
PDFs (`/proprietaire/{id}/compte-rendu/{period}`, `estate_owner_statement`), the agency's terms,
and their cloud folder. Several mandates: a switch between them (`?mandat=<id>`).
`EstateVoter::MANDATE_VIEW`: the management everything; an owner their mandates and payouts.

## The cloud folder (optional: omnibase/drive)

estate does not require omnibase/drive. When it is installed, `Drive\MandatorFolders` is a service
and `Management` takes it as optional:

- `Propriétaires/<name>`, the team's (`drive.default_audience`), with a « Comptes rendus » folder;
- shared by name with the owner once they have an account, **read only**
  (`FileShare::shareWith(..., deposit: false)`): they see it from their own access; the agency puts
  in it what is theirs (the mandate, the leases, the diagnostics);
- each statement sent lands in « Comptes rendus » (the same period's replaced);
- made when the owner is saved in the back office, and again by « Dossier cloud ».

## The back office

Under « Gérance » (only with the activity, `ActivityMenu`):

- **Propriétaires** (`MandatorCrudController`): name, a company or not, SIREN, e-mail, phone,
  address, IBAN, BIC, account, notes; « Dossier cloud ».
- **Mandats de gestion** (`MandateCrudController`): number (given when saved), owner, agency,
  homes, fee in percent and its VAT code, from / until, frequency, reserve; « Comptes rendus ».
- **Dépenses des propriétaires** (`MandateExpenseCrudController`): those of the work orders come by
  themselves; the others are entered here and posted on saving; posted, no longer edited.
- **Reversements aux propriétaires** (`MandatePayoutCrudController`): the payouts and where they
  stand, « Payé ».
- **Comptes rendus de gérance** (`estate_admin_management_statements`): each owner's figures for a
  month, a quarter or a year, their PDF, « Envoyer » one or all; those still to send.
- **Reversements** (`estate_admin_management_payouts`): what each owner is due at a day (the end of
  last month by default), « Émettre les reversements »; those to transfer, ticked, « Télécharger
  le fichier SEPA » with an execution day; « Payé », « Annuler ».
- The dashboard card `estate_management`: what the owners are due at the end of last month, the
  payouts to transfer, the statements to send, the mandates ending within two months.

## To check with an accountant

- **Rents credited to the owner when collected**, the called rent waiting on 4672, rather than
  credited when called (the « quittancement » many agency softwares post, 411 / 4671) with the
  unpaid rents deducted from what is paid out. Both give the same payouts; ours keeps the owner's
  balance equal to the money held for them.
- **4671 per owner, 4672 and 4678 as transit accounts, 7061 for the fees**: the sub-accounts the
  agency's chart (or its software's) may number otherwise; some keep the owners' accounts in a
  separate « comptabilité mandants » ledger rather than in the agency's book.
- **The escrow account in the agency's book**: the owners' money and the agency's in one book,
  apart by account (512x séquestre) - some keep the mandants' accounting as a book of its own. The
  agency's fees stay on the escrow account until it transfers them to its own account: that
  internal transfer (512 own / 512x séquestre, 58 virements internes) is the agency's to post.
- **The fee on the rents and charges collected**: some mandates charge it on the rent alone, or on
  what is called; the rate before tax with VAT added (the usual « % HT » of a mandate).
- **The owner bears the VAT on the expenses** (all taxes included on their account): right for an
  owner not liable to VAT; an owner who opted for VAT (commercial premises) would need the VAT apart.
- **The fee when the book is not liable** (an agency under the franchise): no VAT added.
- **A provision for the property tax, the syndic's calls**: not called from the tenant here; they
  are expenses charged when paid. The annual « décompte » for the owner's tax return (revenus
  fonciers, 2044) is the statements of the year, not yet a document of its own.
- **The financial guarantee and the professional card** (loi Hoguet): out of the software; the
  register of mandates is the mandates' numbers, in order.
