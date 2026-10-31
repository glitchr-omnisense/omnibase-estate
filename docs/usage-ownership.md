# Shared ownership (indivision)

The `ownership` activity (`estate.activities: [..., ownership]`): homes held by several co-owners,
each for a share, managed by an organisation. Nothing of it shows, and its tables are not read,
while the site does not run the activity.

- **An indivision** (`Entity\Ownership`): its name, a few words, its homes (one or several), the
  organisation that manages it - whose books carry it -, its cloud folder.
- **Its co-owners' shares** (`Entity\OwnershipShare`): a person (name, e-mail; their account,
  once they have one, opens the co-owner's space), one of the company's associates, or the
  managing company itself (`company`); an exact fraction (a numerator and a denominator: 1/3 stays
  1/3), its IBAN, the day it starts (`startsOn`) and the first day it no longer holds (`endsOn`).
  A share sold or inherited ends, and the new holder's starts that day. The same co-owner over
  time is one *holder* (`holder()`: `company`, else `email:<their e-mail>`): one account in the
  books, one statement.
- **The check:** on every date the shares change, those in force add up to one exactly
  (`Ownership::problems()`, `isBalanced()`; `Model\Shares` adds fractions without a float). The
  back office says on which date and by how much they do not; an invoice is not divided before.
- **A shared invoice** (`Entity\OwnershipInvoice`): its supplier (a contractor, or a name), date,
  label, number, the amount all taxes included and its VAT code, the expense account (615 by
  default), the home it is for, the file. Divided by the shares in force on its date, or by hand
  (`Enum\SplitMode`: amounts, or percentages), then posted.
- **Calls for funds** (`Entity\OwnershipCall`): one per co-owner and invoice (the managing
  company does not call itself), or a provision called by hand; numbered per year
  (`AF-2026-0007`, the reference their transfer quotes), e-mailed, paid by transfer or recorded by
  hand (`Entity\OwnershipPayment`).
- **The co-owner's statement** (`Model\OwnershipStatement`), for a month, a quarter or a year.
- **The co-owner's space** (`/indivision`), and **the cloud folder** with omnibase/drive.

## The service

```php
use Base\Estate\Service\Ownerships;

$ownerships->split($invoice);                 // the preview: ['shared' => 600000, 'parts' => [holder => [share, fraction, amount]], 'errors' => []]
$calls = $ownerships->post($invoice, new \DateTimeImmutable('+30 days'), send: true);
$ownerships->call($share, 'Provision taxe foncière', 30000, $dueOn);   // a provision, by hand
$ownerships->send($call);                     // e-mailed again (a reminder)
$ownerships->recordPayment($call, 150000, $paidOn, 'VIR 2026-11-03');
$statement = $ownerships->statement($ownership, $share->holder(), $from, $to);
$ownerships->statementPdf($statement);        // dompdf, as the receipts
$ownerships->sendStatement($statement);       // e-mailed, the PDF attached
$ownerships->syncFolder($ownership);          // Indivisions/<name>, shared with the co-owners
```

`Ownership::allocate($amount, $on)` divides an amount by the shares in force that day, to the
cent: each part rounded down, the cents left to the largest remainders, so the parts always add up
(100,00 € by thirds: 33,34 + 33,33 + 33,33).

**The shared cost** of an invoice is its amount, less the VAT the books get back: all taxes
included when the managing organisation's book is not liable (a property company letting homes,
`VatRegime::NONE`), before tax when it is and the code deducts VAT.

## The books: the managing organisation's, marked `ownership=<id>`

An indivision has no legal personality: it is not a company, has no books of its own to keep, and
its result is each co-owner's (each declares their share of the rents, revenus fonciers). Its
manager owes the co-owners an account of what it received and spent for them (Code civil,
art. 815-8: « quiconque perçoit des revenus ou expose des frais pour le compte de l'indivision
doit en tenir un état »; art. 1873-9 for a conventional manager, at least once a year).

estate keeps it in the **managing organisation's own book**, every line marked with the analytic
dimension `ownership=<id>` (named by `Ledger\OwnershipDimensionLabeler`) and `unit=<id>` when it
is about one home - the way the PCG has a manager keep operations carried out in common
(*comptabilité du gérant* of a société en participation): every operation in full in classes 6
and 7, each partner's share transferred to their own current account.

Why the organisation's book rather than one per indivision:

- the co-owners pay into the manager's bank account, and the rents of the indivision's homes
  arrive there: one book's bank reconciliation matches both, as it matches rents today;
- the rents of the homes are already posted there, with their unit; nothing is posted twice nor
  moved;
- it is the treatment the coming `management` activity uses (mandants' accounts, 467);
- the indivision's own figures stay one filter away: the income statement filtered by
  `ownership=<id>` is its *état des recettes et dépenses*, and each co-owner's statement holds
  their part.

### The entries

| What | Journal | Debit | Credit | Source |
|---|---|---|---|---|
| A shared invoice (book not liable) | AC | 6xx, all taxes included | 401 the supplier | `estate:ownership:invoice:<id>` |
| A shared invoice (book liable) | AC | 6xx before tax, 44566 the VAT (`EntryDraft::taxed()`) | 401 the supplier | idem |
| Each co-owner's call (but the company's) | OD | 467 the co-owner | 7551 quote-part de perte transférée | `estate:ownership:call:<id>`, piece = the call's reference |
| A rent received for one of its homes | OD | 6551 quote-part de bénéfice transférée | 467 each co-owner but the company, by the shares that day | `estate:ownership:income:<payment id>` |
| A payment recorded by hand | BQ | 512 | 467 the co-owner (lettered with the call once paid) | `estate:ownership:payment:<id>` |
| A transfer seen at the bank | BQ | 512 | 467 the co-owner (the reconciliation's entry, lettered) | ledger's |

- Each co-owner has their own current account: a ledger `Party` of kind `other` on a 467
  sub-account (`467IND<ownership>-<first share>`), its IBAN their share's, so the reconciliation
  recognises their transfers by reference or by IBAN.
- The managing company's own part stays in its accounts: of the 6 000 € roof it bears 3 000 €
  in 615; of a rent, its half stays in 706.
- 6551 and 7551 are created in the book when first needed (the PCG's « quotes-parts de résultat
  sur opérations faites en commun »).
- The rents are those estate's payments record (by hand, online, or a transfer reconciled):
  `Payments::record()` hands each one to `Ownerships::rentReceived()`; a payment taken back
  (`Payments::cancel()`, a bank line unreconciled) reverses the co-owners' parts.
  `estate:ownership:income` shares out the rents received before the shares were entered.
- A provision called by hand is a request: it is not posted; what the co-owner pays on it is (512 /
  467), and their account is then in credit until an invoice's part uses it.

### Reconciliation

The call's entry carries its reference as the piece: omnibase/ledger's `Matcher` finds a transfer
quoting `AF-2026-0007` (0.99), or the co-owner by their IBAN, and `Reconciler::apply()` posts and
letters it. `Ledger\OwnershipSettlement` listens to `BankLineReconciled` and records the payment on
the call (`bank:<line>:<call>`), without posting it again - as `BankSettlement` does for rents.

## The statement

From the co-owner's side, what the indivision owes them is positive:

- **opening balance**: everything below, from the start until the day before the period;
- **their share of the income**: each rent received for one of its homes, by the shares in force
  the day it came in;
- **their share of the expenses**: each posted invoice's part, at its date;
- **their payments** on their calls;
- **closing balance** = opening + income − expenses + payments;
- **their calls**: those issued in the period, and the older ones still open, with what is left to
  pay.

It is built from estate's records - the same the books are posted from - so it holds with or
without omnibase/ledger. The PDF is `@Estate/pdf/ownership_statement.html.twig`; the e-mail
`@Estate/email/ownership_statement.html.twig` attaches it. Periods are keys: `2026-10`, `2026-Q3`,
`2026` (`Model\OwnershipPeriod`).

## The co-owner's space

`/indivision` (`Controller\Client\CoOwnerController`, route `estate_coowner`), offered by
`Spaces::of()` as `coowner` to an account one of whose shares names it: their account's balance
this year, what is left to pay, their share of the rents and expenses, their calls, the invoices
shared (their files, `/indivision/facture/{id}`), their statements by month, quarter and year
(`/indivision/{id}/releve/{period}`), the co-owners and their shares, and the cloud folder.
`EstateVoter::OWNERSHIP_VIEW`: the management everything; a co-owner their ownership and its
invoices, and of the calls only their own.

## The cloud folder (optional: omnibase/drive)

estate does not require omnibase/drive. When it is installed, `Drive\OwnershipFolders` is a
service (config/services.php loads `src/Drive/` only then) and `Ownerships` takes it as optional:

- `Indivisions/<name>`, the team's (`drive.default_audience`), with a « Factures » folder;
- shared by name with each co-owner who has an account (`FileShare::shareWith(..., deposit:
  true)`): they see it from their own access and may bring files into it, deleting none;
- each posted invoice's file lands in « Factures »;
- made when the ownership or a share is saved in the back office, and again by « Dossier cloud ».

## The back office

Under « Indivisions » (only with the activity, `ActivityMenu`):

- **Indivisions** (`OwnershipCrudController`): name, managing organisation, homes, a few words;
  their shares at a glance, checked; « Relevés », « Dossier cloud ».
- **Quotes-parts** (`OwnershipShareCrudController`): saved, the ownership's shares are checked
  and the result said (« font bien 1 », or « 13/12 au 01/01/2026 »).
- **Factures partagées** (`OwnershipInvoiceCrudController`), then « Répartir»
  (`estate_admin_ownership_invoice`): each co-owner's part, by the shares or typed (amounts,
  percentages), recalculated, then « Passer en comptabilité et appeler les fonds ».
- **Appels de fonds** (`OwnershipCallCrudController`): a provision by hand (« Créer »),
  « Envoyer », « Encaisser » (`estate_admin_ownership_call_payment`).
- **Relevés des indivisaires** (`estate_admin_ownership_statements`): each co-owner's figures for
  a month, a quarter or a year, their PDF, « Envoyer » one or all.
- The dashboard card `estate_ownership`: what the co-owners still owe on their calls, per
  indivision, what is late.

## To check with an accountant

- Per-operation transfers through 6551/7551, rather than one transfer of the year's net result
  at closing (the textbook *comptabilité du gérant*), or 4581 (« associés - opérations faites en
  commun ») rather than 467 for the co-owners' accounts.
- Rents shared when received (as revenus fonciers are declared), not when called.
- A liable book deducts the whole invoice's VAT and shares the cost before tax; an indivision
  that opted for VAT itself would need its own book (a `Book` of its own is not offered yet).
- A provision is not posted until paid (no 4191-like « fonds appelés » account).
- Payouts to the co-owners of what the indivision owes them are not yet a screen: a transfer out
  reconciled against their 467 account settles it in the books, not yet in their statement.
