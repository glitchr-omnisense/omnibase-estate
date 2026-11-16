<?php

namespace Tests\Base\Estate\Service;

use Base\Entity\User;
use Base\Estate\Entity\Associate;
use Base\Estate\Entity\Invitation;
use Base\Estate\Entity\Organisation;
use Base\Estate\Enum\InvitationKind;
use Base\Estate\Service\EstateMailer;
use Base\Estate\Service\Invitations;
use Base\Contract\UnitOfWork;
use Base\Database\Repository\Entities;
use Base\Database\Repository\Repositories;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Accepting an invitation finds what it opens (through the core's repositories) and attaches the
 * account to it, written by the unit of work in one flush.
 */
final class InvitationsTest extends TestCase
{
    public function testTheTargetIsFoundAndTheAccountAttachedOnce(): void
    {
        $organisation = new Organisation('SCI des Tilleuls');
        $associate = new Associate($organisation, 'Hélène', 'Roth', 'helene@example.org');
        $invitation = new Invitation($organisation, InvitationKind::ASSOCIATE, 7, 'helene@example.org', 'Hélène Roth', Invitations::hash('token'), Invitations::expiry(14));
        $user = (new \ReflectionClass(User::class))->newInstanceWithoutConstructor();

        $associates = $this->createStub(Entities::class);
        $associates->method('find')->willReturnCallback(static fn (mixed $id) => 7 === $id ? $associate : null);
        $repositories = $this->createStub(Repositories::class);
        $repositories->method('of')->willReturnCallback(fn (string $class) => Associate::class === $class ? $associates : $this->createStub(Entities::class));
        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->expects(self::once())->method('flush');

        $invitations = new Invitations(
            $unitOfWork,
            $repositories,
            (new \ReflectionClass(EstateMailer::class))->newInstanceWithoutConstructor(),
            $this->createStub(UrlGeneratorInterface::class),
        );

        self::assertSame($associate, $invitations->target($invitation));
        self::assertTrue($invitation->isOpen());

        $invitations->accept($invitation, $user);

        self::assertSame($user, $associate->getUser());
        self::assertFalse($invitation->isOpen(), 'accepted: its link opens nothing any more');
    }
}
