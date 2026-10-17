# Invitations

Nobody signs up: the management invites an associate, a lease's party, a
contractor or a member of the team (`Invitations::invite()`); the link creates
the account or attaches the existing one (`/invitation/{token}`).

The token, its hash (only the SHA-256 is stored), the expiry, the lookup and
the new account are omniscient/omnibase's:

- `Base\Estate\Entity\Invitation` extends `Base\Entity\User\Invitation` (a
  mapped superclass: e-mail, name, `tokenHash`, `createdAt`, `expiresAt`,
  `acceptedAt`, `invitedBy`) and adds the organisation, the kind and the target.
  Same table (`estate_invitation`), same columns: no migration.
- `Base\Estate\Service\Invitations` extends `Base\Service\Invitations`: it says
  what an invitation opens (`target()`) and what accepting does (`accept()`:
  the account on the associate, the party, the contractor; ROLE_ADMIN for a
  member). `createUser()` and `existingUser()` are the core's.

Configuration: `estate.invitation_days` (14), `estate.user_class`.
