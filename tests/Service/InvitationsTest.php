<?php

namespace Tests\Base\Estate\Service;

use Base\Entity\User;
use Base\Estate\Entity\Associate;
use Base\Estate\Entity\Invitation;
use Base\Estate\Entity\Organisation;
use Base\Estate\Enum\InvitationKind;
use Base\Estate\Service\EstateMailer;
use Base\Estate\Service\Invitations;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Accepting an invitation finds what it opens and attaches the account to it,
 * on whichever core the site runs: through the core's unit of work and its
 * repositories (omnisense/omnibase 5.x, which no longer keeps the entity
 * manager; 4.x since its step F2d), else through the entity manager an older
 * core keeps. The 5.x core's base class has no $entityManager: /invitation/{token}
 * answered 500 ("Call to a member function find() on null").
 */
final class InvitationsTest extends TestCase
{
    public function testTheTargetIsFoundAndTheAccountAttachedOnce(): void
    {
        $organisation = new Organisation('SCI des Tilleuls');
        $associate = new Associate($organisation, 'Hélène', 'Roth', 'helene@example.org');
        $invitation = new Invitation($organisation, InvitationKind::ASSOCIATE, 7, 'helene@example.org', 'Hélène Roth', Invitations::hash('token'), Invitations::expiry(14));
        $user = (new \ReflectionClass(User::class))->newInstanceWithoutConstructor();

        $associates = $this->createMock(EntityRepository::class);
        $associates->method('find')->willReturnCallback(static fn (mixed $id) => 7 === $id ? $associate : null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(fn (string $class) => Associate::class === $class ? $associates : $this->createMock(EntityRepository::class));
        $entityManager->method('find')->willReturnCallback(static fn (string $class, mixed $id) => Associate::class === $class && 7 === $id ? $associate : null);
        $entityManager->expects(self::once())->method('flush');

        $invitations = new Invitations(
            $entityManager,
            (new \ReflectionClass(EstateMailer::class))->newInstanceWithoutConstructor(),
            $this->createMock(UrlGeneratorInterface::class),
        );

        self::assertSame($associate, $invitations->target($invitation));
        self::assertTrue($invitation->isOpen());

        $invitations->accept($invitation, $user);

        self::assertSame($user, $associate->getUser());
        self::assertFalse($invitation->isOpen(), 'accepted: its link opens nothing any more');
    }
}
