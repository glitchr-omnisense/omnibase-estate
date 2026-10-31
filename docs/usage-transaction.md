# Sales for third parties (transaction)

The `transaction` activity (`estate.activities: [..., transaction]`): an agency sells homes it does
not own, for their sellers, and finds them buyers. Nothing of it shows, its tables are not read and
nothing of it changes (`Transactions::accept()`, `advance()`, `cancel()`... throw a
`\LogicException`) while the site does not run the activity.

- **The seller** is an owner, `Entity\Mandator` - the same as under management: a person or a
  company, how to reach them, their account (it opens the seller's space). A sale mandate's
  *mandant* is the same legal party as a management mandate's, and an owner who has their flat
  managed and sells their house is one record, one account, one space per activity. Its back-office
  screen (« Propriétaires ») shows with either activity; its management actions (the management
  mandates, the `Propriétaires/<name>` folder) only with management.
- **The sale mandate** (`Entity\SaleMandate`, *mandat de vente*): its seller(s)
  (`estate_sale_mandate_seller`), the agency (the organisation whose books carry the fee), the
  property - one of estate's homes (`unit`), or described in place when the agency does not manage
  it (address, postcode, city, `kind`, `surface`, `rooms`; a home's own figures otherwise) -, the
  asking price, simple or `exclusive`, its first and last days (`startsOn`, `endsOn`, both included:
  a sale mandate has a term, three months by default), the fee (`Enum\FeeBasis`: a percentage of
  the sale price, `feeRate` in basis points - `setFeePercent(5)` -, or a fixed `feeAmount`; all
  taxes included, see below), who pays it (`Enum\FeePayer`: the seller or the buyer), the fee's VAT
  code in the agency's book (`V20` by default), whether the agency holds the deposit
  (`depositHeldByAgency`; the notary does otherwise). Numbered in the register of mandates when
  saved: `VT-2026-0001` (`Transactions::number()`).
- **The buyer** (`Entity\Buyer`, *acquéreur*): how to reach them, their account (the buyer's
  space), what they look for - a budget, a kind, a surface and rooms at least, an area (towns,
  postcodes or districts, separated by commas) - and their financing (`Enum\Financing`: cash or a
  loan, its amount).
- **A visit** (`Entity\Visit`): a mandate, a buyer, when, a few words after.
- **An offer** (`Entity\Offer`): a mandate, a buyer, the amount, when, its conditions (subject to a
  loan and its amount, valid until a day, others as written), where it stands
  (`Enum\OfferStatus`: made, countered - the seller's price kept as `counterAmount` -, accepted,
  refused, withdrawn).
- **The sale** (`Entity\Sale`): begun by the offer accepted, at its price (the counter-offer's when
  there was one), and its steps (`Enum\SaleStep`), each dated, with its documents
  (`Entity\SaleEvent`: the step, its date, a note, a file).

## The service

```php
use Base\Estate\Service\Transactions;

$transactions->number($mandate);                     // VT-2026-0001, given once
$transactions->fee($mandate, $price);                // [all taxes included, before tax, VAT, code]
$transactions->counter($offer, 30500000);            // the seller asks 305 000 €
$sale = $transactions->accept($offer, $day);         // the sale begins; the buyer let into the folder
$transactions->advance($sale, SaleStep::COMPROMISE, $day, [
    'notary' => 'Me Weber', 'deposit' => 1500000,    // the séquestre, in cents
    'notifiedOn' => $handedOver,                     // the withdrawal period runs from the next day
    'loanDeadline' => $byWhen, 'deedPlannedOn' => $planned,
], 'Signé à l\'agence', $file);
$transactions->advance($sale, SaleStep::WITHDRAWAL, $day); // only after the period's last day
$transactions->advance($sale, SaleStep::CONDITIONS, $day);
$transactions->advance($sale, SaleStep::DEED, $day);       // the fee invoiced and posted
$transactions->advance($sale, SaleStep::CLOSED, $day);
$transactions->cancel($sale, 'Prêt refusé', $day, depositForfeited: false);
$transactions->matches($buyer);                      // a short list of mandates for them
$transactions->syncFolder($mandate);                 // Transactions/<number>, shared read-only
```

### The steps

| Step | What it brings | Rule |
|---|---|---|
| Offre acceptée (`ACCEPTED`) | the price, the acceptance's date | one sale per mandate that is not cancelled: another offer waits |
| Compromis signé (`COMPROMISE`) | the date signed, the notary, the deposit, when it was notified to the buyer, the loan's deadline, the acte's planned date | the withdrawal period's end follows |
| Délai de rétractation purgé (`WITHDRAWAL`) | its date | refused until the day after the period's last day |
| Conditions suspensives levées (`CONDITIONS`) | its date (the loan obtained), the acte's date if moved | |
| Acte authentique signé (`DEED`) | its date | the sale is concluded: the fee invoiced; no cancellation after it |
| Dossier clos (`CLOSED`) | its date | the end |
| Vente annulée (`CANCELLED`) | its date, its reason, the deposit given back or forfeited | any step before the acte |

Only the next step is taken (`advance()` says which one it expects); a step dated before the
previous one is refused. Each step is a line of the sale's timeline with its note and document; with
omnibase/drive the document lands in the mandate's folder too.

**The withdrawal period** (`Model\WithdrawalPeriod`, art. L271-1 of the Code de la construction et
de l'habitation, the loi SRU's, ten days since the loi Macron): it runs from the day after the
compromis is notified to the buyer - handed over against a receipt, or the registered letter's first
presentation - and ends on the tenth day at midnight; a period that would end on a Saturday, a
Sunday or a public holiday runs until the next working day (art. 642 of the Code de procédure
civile). The French public holidays are computed, Easter's included (no calendar extension needed);
`end($notifiedOn, alsaceMoselle: true)` adds Good Friday and 26 December. Notified on Wednesday 21
October 2026, the tenth day is Saturday 31, then comes Sunday 1 November, a holiday: the buyer may
withdraw until Monday 2 November included, and the step is taken from the 3rd.

### Matching a buyer

`matches($buyer)`: the mandates running today, neither under an accepted offer nor sold, at the
buyer's budget (up to 5 % above, flagged « à négocier »), of their kind, surface and rooms, in their
area (the mandate's town, postcode or the home's district) - what the mandate does not say does not
rule it out. The cheapest first, ten at most, each with why it fits.

## The books: the fee at the acte, in the agency's book (loi Hoguet)

An estate agent is paid only once the sale is concluded: no fee is due, nor may any sum be received
for it, before the operation is concluded and recorded in one written deed holding both parties'
commitments (loi Hoguet n° 70-9 du 2 janvier 1970, art. 6; décret n° 72-678, art. 74). With a
compromis under conditions, the operation is concluded when the conditions are met and the sale
repeated before the notary - the acte authentique: that is when the agency's right to its fee is
certain and when it invoices it. The French treatment follows:

- **Nothing before the acte.** An offer, a compromis, the conditions lifted: no entry, nothing of it
  is the agency's income nor a receivable. The fee expected is a figure on the dashboard, not in
  the books.
- **The fee at the acte**, invoiced to whoever the mandate names (`FeePayer`): the seller (their
  customer account, `PartyKind::CUSTOMER`, 411 + `SELLER<id>`) or the buyer (411 + `BUYER<id>`) - a
  receivable, usually paid by the notary out of the price. Numbered `FH-<year>-<n>` (*facture
  d'honoraires*), the number as the entry's piece, so the notary's transfer is matched by the bank
  reconciliation against the open item.
- **The agency's income is its fee** on 7062 « Honoraires de transaction » (created when first
  needed), its VAT on 44571 when the book is liable (`EntryDraft::taxed()`).
- **The deposit (séquestre)** is usually held by the notary: recorded on the sale (its amount, who
  holds it), not posted. When the mandate says the agency holds it, it is the buyer's money held for
  them - as the owners' money under management (loi Hoguet: the escrow account and the financial
  guarantee): in on the escrow account at the compromis (`BankAccount::$escrow`, 512 otherwise) on
  the buyer's current account 467 + `B<id>`, out at the acte (paid to the notary, part of the price)
  or at the cancellation (given back to the buyer, or paid to the seller when the buyer's fault
  forfeits it), 467 lettered.
- every line carries the dimensions **`transaction=<sale mandate id>`** (named by
  `Ledger\TransactionDimensionLabeler`: its number and property) and **`unit=<id>`** for one of
  estate's homes: the income statement filtered by transaction gives each sale's fee.

**The fee is stated all taxes included.** French transaction fees are shown and agreed all taxes
included (arrêté du 10 janvier 2017 on the information of consumers by estate agents: the barème and
the ads give them TTC), so the mandate's rate or fixed amount is TTC; when the agency's book is
liable the VAT is drawn out of it (`Tax::split($total, taxIncluded: true)`, rounded to the cent half
up, the base taking the remainder), and when it is not (an agency under the franchise) the whole is
the fee. 5 % of 300 000 € is 15 000 € TTC: 12 500 € of fees and 2 500 € of VAT. The percentage
applies to the price agreed (the accepted offer's, or the counter-offer's): the price « net
vendeur », the fee on top of it when the buyer pays it.

### The entries

| What | Journal | Debit | Credit | Source |
|---|---|---|---|---|
| The acte signed: the fee | VT | 411 the payer (seller or buyer), all taxes included | 7062 before tax, 44571 its VAT | `estate:transaction:fee:<sale id>`, piece = `FH-2026-0001` |
| The fee paid by the notary | BQ | 512 | 411 the payer (the reconciliation's entry, lettered) | ledger's |
| The deposit the agency holds, at the compromis | BQ | 512x the escrow account | 467 the buyer | `estate:transaction:deposit:<sale id>`, piece = `VT-2026-0001` |
| … paid to the notary at the acte, given back or forfeited at a cancellation | BQ | 467 the buyer | 512x the escrow account (467 lettered) | `estate:transaction:deposit:<sale id>:out` |

Each posting carries its source: nothing is posted twice. Without omnibase/ledger, the sales,
their steps and their fees are kept all the same; nothing is posted.

## The spaces

- **The seller** (`/vendeur`, `Controller\Client\TransactionController::seller()`, route
  `estate_seller`), offered by `Spaces::of()` as `seller` to an account a seller of a sale mandate
  names: the property, the price asked (agreed, once an offer is accepted), the terms, the visits -
  their date and the feedback, never who visited -, the offers (amount, conditions, state; the buyer
  named once their offer is accepted, as the compromis names them), the sale's steps with their dates
  and documents, the cloud folder. Several mandates: a switch (`?mandat=<id>`).
- **The buyer** (`/acquereur`, `estate_buyer`), offered as `buyer` to a buyer's account: their
  offers and where they stand and, for the one accepted, the sale's steps, their documents and the
  folder; the fee when they pay it.
- A step's document (`/vente/document/{id}`, `estate_transaction_document`): the agency, the
  sale's sellers and its buyer.
- `EstateVoter::SALE_VIEW` on `SaleMandate`, `Visit`, `Offer`, `Sale`, `SaleEvent`: the agency's
  management everything; a seller their mandates and all that happens on them; a buyer their own
  offers and their sale - not the mandate's other offers nor its visits.

## The cloud folder (optional: omnibase/drive)

When omnibase/drive is installed, `Drive\SaleFolders` is a service and `Transactions` takes it as
optional:

- `Transactions/<mandate number>`, the team's (`drive.default_audience`), made when the mandate is
  saved in the back office, and again by « Dossier cloud »;
- shared by name, **read only**, with each seller who has an account and, once an offer is accepted,
  with its buyer; a cancelled sale's buyer is taken out of it;
- each step's document lands in it (`2026-10-20 — Compromis.pdf`); the agency puts in it the
  diagnostics and anything else.

## The back office

Under « Transactions » (only with the activity, `ActivityMenu`):

- **Propriétaires** (`MandatorCrudController`): the sellers, shared with management.
- **Mandats de vente** (`SaleMandateCrudController`): number (given when saved), sellers, agency, a
  home or the property described, price, exclusive, from / until, fee and who pays it, VAT code,
  who holds the deposit; « Suivi », « Dossier cloud ».
- **Acquéreurs** (`BuyerCrudController`): what they look for, their financing, their account;
  « Biens correspondants » (`estate_admin_transaction_matches`).
- **Visites** (`VisitCrudController`) and **Offres d'achat** (`OfferCrudController`; an offer
  answered is no longer edited): opened on a mandate or a buyer from their pages
  (`?mandate=<id>`, `?buyer=<id>`).
- **A mandate's page** (`estate_admin_transaction_mandate`, « Suivi »): the property and its terms
  (the fee in euros), the sale's steps with their dates and documents, the form of the next step
  (its date and fields, a note, a document), « Annuler la vente » with its reason; the offers with
  « Accepter », « Contre-proposer », « Refuser », « Retirée »; the visits; the cancelled sales.
- **Ventes en cours** (`estate_admin_transaction_sales`): each sale's step, what comes next and when,
  its fee; the quarter's fees, invoiced and expected; the offers waiting; the mandates ending
  without a sale.
- The dashboard card `estate_transaction`: the quarter's fees (invoiced at the actes signed in it,
  expected from the actes planned in it), the sales going on with their next date, the offers
  waiting for an answer, the mandates ending within a month without a sale.

## To check with an accountant

- **The fee recognised at the acte authentique**, not at the compromis nor when the conditions are
  lifted: the loi Hoguet's moment the right to the fee is certain. An agency closing its year
  between a compromis and its acte shows nothing of that sale; some note it as a commitment.
- **The fee stated all taxes included** and its VAT drawn out of it, as the barème shows it; a
  mandate that states its fee before tax would need the VAT added instead.
- **411 per payer** (the seller or the buyer as a customer) and **7062 for the fees**: the
  sub-accounts the agency's chart may number otherwise (706, 7061 being the management fees here).
- **The fee paid by the notary out of the price**, recognised by the invoice number on the bank
  line: when the notary pays the fee net of anything, or several fees at once, the reconciliation
  splits it by hand.
- **The deposit held by the agency** on its escrow account against 467 per buyer, out at the acte to
  the notary: some agencies keep the deposits in a separate « séquestre » ledger, or deduct their fee
  from the deposit with the seller's written agreement (not done here: the deposit leaves whole).
- **A deposit forfeited** (the buyer's fault after the withdrawal period, the clause pénale) goes to
  the seller in full here; an agreement that gives the agency a share would need its own entry.
- **Alsace-Moselle**: Good Friday and 26 December are local holidays; whether they extend the
  withdrawal period for a property there is the notary's to confirm (`alsaceMoselle: true`, not
  used by default).
- **The register of mandates** (loi Hoguet): the mandates' numbers in order, `VT-` for the sales and
  `MG-` for the management, one sequence each; some keep one register for both.
- **The professional card « transactions sur immeubles »** and the financial guarantee for the
  deposits held: out of the software.
