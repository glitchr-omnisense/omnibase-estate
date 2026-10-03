<?php

namespace Base\Estate\Entity;

use Base\Entity\User\Invitation as BaseInvitation;
use Base\Estate\Enum\InvitationKind;
use Doctrine\ORM\Mapping as ORM;

/**
 * An e-mail that opens a space: whoever follows its link before it runs out
 * gets an account (or signs in with theirs), attached to what it was made
 * for - an associate, a lease's party, a contractor, the management team.
 * Only the token's hash is kept. The e-mail, the token, the expiry and the
 * acceptance are glitchr/omnibase's (Base\Entity\User\Invitation, a mapped
 * superclass): same columns, same table as before.
 */
#[ORM\Entity]
#[ORM\Table(name: 'estate_invitation')]
class Invitation extends BaseInvitation
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Organisation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Organisation $organisation;

    #[ORM\Column(length: 16, enumType: InvitationKind::class)]
    private InvitationKind $kind;

    /** The id of the Associate, LeaseParty, Contractor or Member it opens. */
    #[ORM\Column]
    private int $targetId;

    public function __construct(Organisation $organisation, InvitationKind $kind, int $targetId, string $email, string $name, string $tokenHash, \DateTimeImmutable $expiresAt)
    {
        parent::__construct($email, $name, $tokenHash, $expiresAt);
        $this->organisation = $organisation;
        $this->kind = $kind;
        $this->targetId = $targetId;
    }

    public function __toString(): string { return sprintf('%s (%s)', $this->email, $this->kind->value); }
    public function getId(): ?int { return $this->id; }
    public function getOrganisation(): Organisation { return $this->organisation; }
    public function getKind(): InvitationKind { return $this->kind; }
    public function getTargetId(): int { return $this->targetId; }
}
