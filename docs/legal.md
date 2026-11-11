---
title: Legal rules
order: 55
---

# Legal rules

Every rule of French law estate codes, where it is coded, its source and the version of the text
it was written from. The texts were read on Légifrance on 2026-11-11 (no PISTE key was configured
for omnilex/legifrance: the public pages). **None of this has been checked by a lawyer**: each
line is to be confirmed before a site relies on it, and a rule the text left unclear is not coded
- the field is left to the team, and the table says so.

## Rent revisions

| Rule | Where | Source (version) | To confirm |
|---|---|---|---|
| A home's rent is revised yearly on the IRL; a year not claimed within the year is lost | `RentRevisions::quarters()` | loi n° 89-462 du 6 juillet 1989, art. 17-1 | how the change is measured after a lost year (from the year before, no catch-up) |
| The IRL's yearly change capped at 3.5 % (2.5 % overseas) for the quarters T3 2022 to T1 2024 | `Model\IndexCaps` | loi n° 2022-1158 du 16 août 2022, art. 12, II, as amended by loi n° 2023-568 du 7 juillet 2023, art. 2 (version in force since 9 July 2023) | Corsica's cap (3.5 % applied) |
| The ILC's yearly change taken into account for a small or medium-sized business's rent capped at 3.5 % for the quarters T2 2022 to T1 2024; the cap « définitivement acquis » | `IndexCaps::limit()`, `IndexCaps::spansCap()` | loi n° 2022-1158 du 16 août 2022, art. 14, as amended by loi n° 2023-568, art. 1 (version in force since 9 July 2023) | whether the tenant is an SME (annex I to regulation (EU) n° 651/2014: declared by the team, not checked); a change over several years across the period is **not coded** (left to the team); whether the cap reaches an indexation clause as well as a triennial revision (the article says « la révision du loyer »: estate applies it to both) |
| A commercial lease's revision is asked three years at least after the tenant's entry or the day the last price applied, and takes effect on the day it is asked | `RentRevisions::nextOn()` | Code de commerce, art. L145-38, al. 1 to 3 (version in force since 20 June 2014, loi n° 2014-626) | the day of the request is the team's (the page applies from the date typed) |
| The triennial revision's change cannot exceed the ILC's or ILAT's variation since the last amicable or judicial fixing of the rent, unless a material change of the local commercial factors that moved the rental value by more than 10 % is proved | `RentRevisions::rentAt()` as a ceiling, `revise.html.twig` | Code de commerce, art. L145-38, al. 4 (version of 20 June 2014) | **which quarter** the variation ends at (the article does not say: typed by the team); the case of the proof (10 %, then 10 % a year at most) is **not coded** |
| An indexation clause is the contract's: yearly on the anniversary, the same quarter a year later, from the reference with no year lost | `RentRevisions::quarters()` | the lease's clause (no article) | the clause's own wording prevails; a clause on another rhythm is not modelled |
| A revision may be asked when an indexation clause moved the rent by more than a quarter, its result 10 % a year at most | not coded (said in the docs) | Code de commerce, art. L145-39 (version of 20 June 2014) | — |
| The index of a home is the IRL; of a commercial or professional lease the ILC or the ILAT; the triennial revision is a commercial lease's | `Lease::validateTerms()`, `LeaseKind` | Code de commerce, art. L145-37 and L145-38 (version of 20 June 2014: the leases « régis par les dispositions du présent chapitre », the ILC and ILAT of art. L112-2 of the code monétaire et financier) | a professional lease (loi n° 86-1290, art. 57 A, not read) on the ILAT or the ILC as its contract says |
| A commercial lease made or renewed since 26 May 2026, of premises for retail, wholesale or commercial or craft services: what the tenant pays as a guarantee cannot exceed a quarter's rent | `Model\LeaseRules::warnings()`, a warning on the lease's page | Code de commerce, art. L145-40, al. 2, and art. L145-32-1 (loi n° 2026-403 du 26 mai 2026, art. 62; version in force since 28 May 2026; its application, art. 62, II, B) | whether the premises are of that kind (the team's); whether the quarter's rent is counted with or without VAT (estate compares the rent excluding VAT and charges); whether it reaches a guarantor's commitment (« des engagements et des garanties de toute nature ») |

Read, not coded: art. L145-32-1 (a tenant of such premises may ask to pay monthly: estate calls
rents monthly anyway), art. L145-33 (the valeur locative), art. L145-36, L145-38-1 (the « tunnel »
clause on the ILC), the rest of art. L145-40 (interest on rents paid in advance beyond two terms,
the deposit's return within three months of the keys).

## VAT on rents

No rule of tax law is coded: the landlord's option for VAT, its scope (the charges) and its rate
are the team's and the book's (a code of omnibase/ledger). estate only applies the code the lease
names, when the book says it is liable.

| Decision | Where | To confirm |
|---|---|---|
| A lease with VAT is called and posted with the VAT of its code, the charges only if the lease says so | `Books::vatOf()`, `Books::rentCalled()` | whether the option covers the charges (left to the team, off by default) - CGI art. 260 not read |
| A commercial tenant's proof of payment is the same quittance, VAT included | `@Estate/pdf/receipt.html.twig` | estate's quittance was written for homes; no text was read, for this step, that asks a commercial landlord for one or shapes it |
| The rent notice of a lease with VAT is not an invoice | `@Estate/pdf/notice.html.twig` | the invoice's mentions (CGI, annex II, art. 242 nonies A), not read: **not coded** |
