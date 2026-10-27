---
title: Invitations
order: 33
---

# Invitations

Nobody signs up: the management invites an associate, a lease's party, a
contractor or a member of the team (`Invitations::invite()`); the link creates
the account or attaches the existing one (`/invitation/{token}`).

The token, its hash (only the SHA-256 is stored), the expiry, the lookup and
the new account are omnisense/omnibase's:

- `Base\Estate\Entity\Invitation` extends `Base\Entity\User\Invitation` (a
  mapped superclass: e-mail, name, `tokenHash`, `createdAt`, `expiresAt`,
  `acceptedAt`, `invitedBy`) and adds the organisation, the kind and the target.
  Same table (`estate_invitation`), same columns: no migration.
- `Base\Estate\Service\Invitations` extends `Base\Service\Invitations`: it says
  what an invitation opens (`target()`) and what accepting does (`accept()`:
  the account on the associate, the party, the contractor; ROLE_ADMIN for a
  member). `createUser()` and `existingUser()` are the core's.

Configuration: `estate.invitation_days` (14), `estate.user_class`.

## Inviting

`Base\Estate\Service\Invitations::invite(Associate|LeaseParty|Contractor|Member $target, ?User $by = null, bool $send = true)`
returns the invitation and its token (sent, never stored): its kind (`ASSOCIATE`, `TENANT`,
`CONTRACTOR`, `MEMBER`), the organisation, the target's id, e-mail and name, expiring after
`estate.invitation_days`. With `$send`, the `invitation` e-mail ("<organisation> vous ouvre son
accès") carries `/invitation/{token}`, reply-to the organisation.

The back office invites from a record (`estate_admin_invite`, `/admin/estate/invite/{kind}/{id}`,
`kind` among `member`, `associate`, `tenant`, `contractor`, a link with its token).

## Accepting

`/invitation/{token}` (`estate_invitation`, 48 hex characters):

- an unknown, used or expired token: `invitation_expired.html.twig`, status 410;
- signed in: a button (POST, token `invitation_<id>`) attaches the account;
- an account exists for the address: the page says so (sign in first);
- otherwise a form (username, password) creates the account of `estate.user_class`, attaches
  it and signs the user in (`form_login`, firewall `main`); a username taken is a form error.

`accept()` sets the target's `user`; a member who is not `ROLE_STAFF` gets `ROLE_ADMIN`. What the
invitation opened must still exist (`RuntimeException` otherwise).
