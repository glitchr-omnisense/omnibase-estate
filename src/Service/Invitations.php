<?php

namespace Base\Estate\Service;

use Base\Entity\User;
use Base\Estate\Entity\Associate;
use Base\Estate\Entity\Contractor;
use Base\Estate\Entity\Invitation;
use Base\Estate\Entity\LeaseParty;
use Base\Estate\Entity\Member;
use Base\Estate\Entity\Organisation;
use Base\Estate\Enum\InvitationKind;
use Base\Service\Invitations as BaseInvitations;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Nobody signs up: the management invites. An invitation names what it
 * opens (an associate, a lease's party, a contractor, the management team);
 * its link creates the account - or, for someone who has one, attaches it.
 * The token, its hash, the expiry, the lookup and the new account are
 * glitchr/omnibase's Base\Service\Invitations; this says what an estate
 * invitation opens and what accepting it does.
 */
class Invitations extends BaseInvitations
{
    public function __construct(
        EntityManagerInterface $entityManager,
        private readonly EstateMailer $mailer,
        private readonly UrlGeneratorInterface $urls,
        #[Autowire('%estate.invitation_days%')] private readonly int $days = 14,
        #[Autowire('%estate.user_class%')] string $userClass = 'App\Entity\User',
    ) {
        parent::__construct($entityManager, $userClass);
    }

    /** @return array{0: Invitation, 1: string} the invitation and its token (sent, never stored) */
    public function invite(Associate|LeaseParty|Contractor|Member $target, ?User $by = null, bool $send = true): array
    {
        [$kind, $organisation, $name] = match (true) {
            $target instanceof Associate => [InvitationKind::ASSOCIATE, $target->getOrganisation(), $target->getName()],
            $target instanceof LeaseParty => [InvitationKind::TENANT, $target->getLease()->getOrganisation(), $target->getName()],
            $target instanceof Contractor => [InvitationKind::CONTRACTOR, $target->getOrganisation(), $target->getContactName() ?? $target->getCompany()],
            $target instanceof Member => [InvitationKind::MEMBER, $target->getOrganisation(), $target->getName()],
        };
        $token = self::token();
        $invitation = $this->save((new Invitation($organisation, $kind, (int) $target->getId(), $target->getEmail(), $name, self::hash($token), self::expiry($this->days)))
            ->setInvitedBy($by));

        if ($send) {
            $this->mailer->send($target->getEmail(), sprintf('%s vous ouvre son accès', $organisation->getName()), 'invitation', [
                'invitation' => $invitation,
                'organisation' => $organisation,
                'url' => $this->urls->generate('estate_invitation', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL),
            ], $organisation->getEmail());
        }

        return [$invitation, $token];
    }

    public function find(string $token): ?Invitation
    {
        return $this->findOpen(Invitation::class, $token);
    }

    public function target(Invitation $invitation): Associate|LeaseParty|Contractor|Member|null
    {
        return $this->entityManager->find(match ($invitation->getKind()) {
            InvitationKind::ASSOCIATE => Associate::class,
            InvitationKind::TENANT => LeaseParty::class,
            InvitationKind::CONTRACTOR => Contractor::class,
            InvitationKind::MEMBER => Member::class,
        }, $invitation->getTargetId());
    }

    /** The invitation's account, attached to what it opens. */
    public function accept(Invitation $invitation, User $user): void
    {
        $target = $this->target($invitation) ?? throw new \RuntimeException('What this invitation opened no longer exists.');
        $target->setUser($user);
        if ($target instanceof Member && !\in_array('ROLE_STAFF', $user->getRoles(), true)) {
            $user->setRoles(array_values(array_unique([...$user->getRoles(), 'ROLE_ADMIN'])));
        }
        $invitation->accept();
        $this->entityManager->flush();
    }
}
