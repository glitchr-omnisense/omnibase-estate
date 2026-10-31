# omnibase/estate

Property management for the sites built on [omnisense/omnibase](https://github.com/glitchr-omnisense/omnibase):
the organisations that own or manage homes (an SCI, an agency), their buildings and units,
associates, leases and tenants, rent calls, receipts and online payment, contractors and work
orders, invitations, and a private space for each of those people - posting to
[omnibase/ledger](https://github.com/glitchr-omnisense/omnibase-ledger) when the organisation
keeps its books there. It is a Symfony bundle (`Base\Estate\EstateBundle`) on the core 3 or 4,
with its back office on omnibase/admin. Its words on the pages and in the e-mails are French: it
was written for French residential lettings (the loi du 6 juillet 1989, the IRL revision, the
quittance).

Nobody signs up. The management invites an associate, a tenant, a contractor or a member of its
team; the link creates the account or attaches an existing one; what each person may open comes
from what they are to the organisation, never from a role stored on the account.

- **The management** (a `Member`, or any `ROLE_ADMIN`): the back office - every record, the
  rents to call and collect, the work orders to give, the applications, the messages.
- **Associates** (`/associes`): the company's figures, its feed, its deadlines, its documents
  and - with omnibase/ledger - its accounts and each one's share of the result.
- **Tenants** (`/locataire`): their lease, its rents (paid online, notices and receipts to
  download, a certificate of rent up to date), documents, insurance, messages, the problems they
  report, the building's notices.
- **Contractors** (`/artisan`): the jobs given to them, with the address and the access, and a
  form to give a date, a quote, an invoice and a report.
- **Co-owners** (`/indivision`, with the `ownership` activity): their shared ownership, their
  account, calls for funds, the invoices shared, their statements, its cloud folder.
- **Owners under management** (`/proprietaire`, with the `management` activity): their homes and
  tenants, their account, their statements (comptes rendus de gérance), their payouts, their
  cloud folder.
- **Sellers and buyers** (`/vendeur`, `/acquereur`, with the `transaction` activity): a seller's
  mandate, its visits and offers, the sale's steps and documents; a buyer's offers and, once one
  is accepted, the sale's steps; the sale's cloud folder.
- **The public**: the published homes (`/biens`), a visit request, an application form.

## How this manual is organised

- [Installation](installation.md): requirements and suggestions, the bundle, the routes, the
  schema, the crons.
- [Organisations, homes and leases](concepts.md): every entity, its fields and methods, the
  enums.
- [Rents](usage-rents.md): calling, reminding, payments, receipts, online payment, the IRL
  revision, the books.
- [Shared ownership](usage-ownership.md): indivisions, co-owners' shares over time, shared
  invoices, calls for funds, statements, the co-owner's space, the cloud folder, and how it is
  kept in the books.
- [Property management for third parties](usage-management.md): owners, mandates, rents to the
  owners' accounts, fees with VAT, expenses, statements, payouts and the SEPA file, the escrow
  account, the owner's space and folder, and how it is kept in the books.
- [Sales for third parties](usage-transaction.md): sellers, sale mandates, buyers matched,
  visits, offers, the sale's French steps and the withdrawal period, the fee at the acte with its
  VAT, the deposit, the seller's and the buyer's spaces and folder, and how it is kept in the books.
- [Works, documents and messages](usage-works.md): work orders, files, the tenants' messages,
  notices.
- [The public site and applications](usage-applications.md): the homes, visits, applications.
- [Invitations](invitations.md): inviting, accepting.
- [Spaces and access](usage-spaces.md): `Spaces`, `EstateVoter`, the four spaces, the Twig
  functions.
- [The back office](back-office.md): the screens, the actions, the dashboard tiles.
- [Configuration reference](configuration.md): `estate:`, the services.
- [Testing](testing.md).
