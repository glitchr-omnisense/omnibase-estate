<?php

namespace Tests\Base\Estate\Service;

use Base\Entity\User;
use Base\Estate\Entity\Mandate;
use Base\Estate\Entity\MandatePayout;
use Base\Estate\Entity\Mandator;
use Base\Estate\Entity\Organisation;
use Base\Estate\Repository\LeaseRepository;
use Base\Estate\Security\Voter\EstateVoter;
use Base\Estate\Service\Activities;
use Base\Estate\Service\Spaces;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * The owner's space exists only while the site runs `management`: off,
 * nobody is an owner and the management tables are not even read; on, an
 * owner with an account is offered their space and sees their own mandate
 * and payouts - nobody else's.
 */
final class OwnerSpacesTest extends TestCase
{
    /** @var list<class-string> */
    private array $read = [];

    private function spaces(array $activities, array $mandators, array $mandates): Spaces
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(function (string $class) use ($mandators, $mandates) {
            $this->read[] = $class;
            $repository = $this->createStub(EntityRepository::class);
            $repository->method('findBy')->willReturnCallback(fn (array $criteria) => match ($class) {
                Mandator::class => array_values(array_filter($mandators, fn (Mandator $m) => $m->getUser() === ($criteria['user'] ?? null))),
                Mandate::class => array_values(array_filter($mandates, fn (Mandate $m) => \in_array($m->getMandator(), $criteria['mandator'] ?? [], true))),
                default => [],
            });

            return $repository;
        });
        $leases = $this->createStub(LeaseRepository::class);
        $leases->method('findForUser')->willReturn([]);

        return new Spaces($entityManager, $leases, $this->createStub(Security::class), new Activities($activities));
    }

    private static function withId(object $entity, int $id): object
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);

        return $entity;
    }

    public function testTheActivityOffShowsNoOwnerSpace(): void
    {
        $user = (new \ReflectionClass(User::class))->newInstanceWithoutConstructor();
        $spaces = $this->spaces(['assets', 'ownership'], [(new Mandator('Hélène Roth'))->setUser($user)], []);

        self::assertSame([], $spaces->mandators($user));
        self::assertSame([], $spaces->mandates($user));
        self::assertSame([], $spaces->of($user));
        self::assertNotContains(Mandator::class, $this->read, 'the management tables are not read');
    }

    public function testAnOwnerSeesTheirOwnMandateAndNobodyElses(): void
    {
        $user = self::withId((new \ReflectionClass(User::class))->newInstanceWithoutConstructor(), 41);
        $other = self::withId((new \ReflectionClass(User::class))->newInstanceWithoutConstructor(), 42);
        $agency = self::withId(new Organisation('Agence Kléber'), 1);
        $helene = self::withId((new Mandator('Hélène Roth'))->setUser($user), 5);
        $paul = self::withId((new Mandator('Paul Klein'))->setUser($other), 6);
        $hers = self::withId(new Mandate($agency, $helene), 3);
        $his = self::withId(new Mandate($agency, $paul), 4);
        $spaces = $this->spaces(['management'], [$helene, $paul], [$hers, $his]);

        self::assertSame([['key' => 'owner', 'route' => 'estate_owner']], $spaces->of($user));
        self::assertSame([$hers], $spaces->mandates($user));

        $voter = new EstateVoter($spaces);
        $token = new UsernamePasswordToken($user, 'main');
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $hers, [EstateVoter::MANDATE_VIEW]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, new MandatePayout($hers, new \DateTimeImmutable('2026-10-31'), 100, 0, 100), [EstateVoter::MANDATE_VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $his, [EstateVoter::MANDATE_VIEW]), 'not another owner\'s');
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $paul, [EstateVoter::MANDATE_VIEW]));
    }
}
