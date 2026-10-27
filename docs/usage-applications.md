---
title: The public site and applications
order: 32
---

# The public site and applications

## The homes

`/biens` (`estate_units`) lists the published units - those free first (by `availableFrom`),
then by position and name - with `?type=<UnitKind value>` and `?libres=1` (free only); the page
also gets every published unit, for a filter in the page. `/biens/{slug}` shows one, three others,
and a visit request (name, e-mail, phone, message): e-mailed to the organisation, reply-to the
visitor. A filled trap field (`website`) is a robot: thanked all the same, nothing sent.

## Applying

`/biens/{slug}/candidature` (`estate_unit_apply`) is open while the home is offered
(`availableFrom` set). The form (`Base\Estate\Model\ApplicationRequest`):

| Field | Constraints |
|---|---|
| `firstName`, `lastName` | not blank, 80 at most |
| `email` | not blank, an e-mail |
| `phone` | 40 at most |
| `occupants` | 1 to 12 |
| `moveInOn` | defaults to the later of the home's availability and today |
| `occupation` | `PERMANENT`, `FIXED_TERM`, `CIVIL_SERVANT`, `SELF_EMPLOYED`, `STUDENT`, `RETIRED`, `JOB_SEEKER`, `OTHER` |
| `income` | the household's net monthly income, in cents, 0 or more |
| `guarantee` | `VISALE`, `PERSON`, `COMPANY`, `NONE` |
| `dossierUrl` | a link to the applicant's file (DossierFacile) |
| `message` | 3000 at most |
| `consent` | required |

A filled trap is thanked and nothing kept.

## From received to let

`Base\Estate\Service\Applications`:

| Step | What happens |
|---|---|
| `submit(Application)` | kept (`NEW`); an activity line; the organisation told; the applicant acknowledged |
| `shortlist(Application)` | `SHORTLISTED`; nobody told |
| `inviteToVisit(Application, \DateTimeImmutable $at, ?string $message)` | `VISIT`, the date; the applicant invited, with the management's words |
| `decline(Application, ?string $message, bool $notify = true)` | `DECLINED` (only an open one); the applicant told politely |
| `accept(Application, \DateTimeImmutable $startOn, bool $declineOthers = false)` | the lease made - the applicant its tenant, the home's rent, charges and deposit, starting then, its reference `<initials>-<year>-<001>` -; the home no longer offered; `ACCEPTED`; a "lease started" line; with `$declineOthers`, the other open applications declined |
| `purge(int $months = 3)` | the applications decided (accepted or declined) more than that long ago deleted: `estate:applications:purge`, daily |

An open application is `NEW`, `SHORTLISTED` or `VISIT`; deciding one sets `decidedAt`.
`Application::getIncomeRatio()` is the income over rent and charges (landlords look for about 3).
The lease reference's initials are the organisation name's first letters, without legal forms
and small words (SCI, SARL, DE, LA...).
