<?php

namespace Tests\Base\Estate\Service;

use Base\Entity\User;
use Base\Estate\Repository\LeaseRepository;
use Base\Estate\Service\Activities;
use Base\Estate\Service\Spaces;
use Base\Estate\Entity\OwnershipShare;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The co-owner's space exists only while the site runs `ownership`: off,
 * nobody is a co-owner and the ownership tables are not even read (a site
 * that never ran the activity need not have them).
 */
final class OwnershipSpacesTest extends TestCase
{
    public function testTheActivityOffShowsNoCoOwnerSpace(): void
    {
        $read = [];
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(function (string $class) use (&$read) {
            $read[] = $class;
            $repository = $this->createStub(EntityRepository::class);
            $repository->method('findBy')->willReturn([]);

            return $repository;
        });
        $leases = $this->createStub(LeaseRepository::class);
        $leases->method('findForUser')->willReturn([]);
        $user = (new \ReflectionClass(User::class))->newInstanceWithoutConstructor();

        $spaces = new Spaces($entityManager, $leases, $this->createStub(Security::class), new Activities(['assets']));

        self::assertSame([], $spaces->coOwnerships($user));
        self::assertSame([], $spaces->ownerships($user));
        self::assertSame([], $spaces->of($user));
        self::assertNotContains(OwnershipShare::class, $read, 'the ownership tables are not read');
    }
}
