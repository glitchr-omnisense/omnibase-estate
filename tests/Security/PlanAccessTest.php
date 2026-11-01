<?php

namespace Tests\Base\Estate\Security;

use Base\Entity\User;
use Base\Estate\Entity\Associate;
use Base\Estate\Entity\Lease;
use Base\Estate\Entity\LeaseParty;
use Base\Estate\Entity\Mandate;
use Base\Estate\Entity\Mandator;
use Base\Estate\Entity\Member;
use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\Unit;
use Base\Estate\Enum\PartyRole;
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
 * Who sees a home's plan (EstateVoter::UNIT_PLAN): its tenant and co-tenant while their lease runs,
 * the team, the company's associates, an owner whose mandate holds it - not a guarantor, not the
 * tenant of a lease ended, not another home's tenant, not a stranger.
 */
final class PlanAccessTest extends TestCase
{
    private int $ids = 100;

    private function user(): User
    {
        $user = (new \ReflectionClass(User::class))->newInstanceWithoutConstructor();

        return $this->withId($user);
    }

    private function withId(object $entity): object
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, ++$this->ids);

        return $entity;
    }

    /**
     * @param list<Lease>    $leases
     * @param list<Member>   $members
     * @param list<Associate> $associates
     * @param list<Mandator> $mandators
     * @param list<Mandate>  $mandates
     * @param list<Organisation> $organisations what a ROLE_ADMIN runs
     */
    private function voter(array $leases, array $members = [], array $associates = [], array $mandators = [], array $mandates = [], bool $admin = false, array $organisations = []): EstateVoter
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(function (string $class) use ($members, $associates, $mandators, $mandates, $organisations) {
            $repository = $this->createStub(EntityRepository::class);
            $repository->method('findBy')->willReturnCallback(fn (array $criteria) => array_values(array_filter(match ($class) {
                Member::class => $members,
                Associate::class => $associates,
                Mandator::class => $mandators,
                Mandate::class => $mandates,
                Organisation::class => $organisations,
                default => [],
            }, fn (object $o) => match ($class) {
                Organisation::class => true,
                Mandate::class => \in_array($o->getMandator(), $criteria['mandator'] ?? [], true),
                default => $o->getUser() === ($criteria['user'] ?? null),
            })));

            return $repository;
        });
        $repository = $this->createStub(LeaseRepository::class);
        $repository->method('findForUser')->willReturnCallback(fn (User $user) => array_values(array_filter($leases, fn (Lease $lease) => $lease->getParties()->exists(fn ($k, LeaseParty $p) => $p->getUser() === $user))));
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn($admin);

        return new EstateVoter(new Spaces($entityManager, $repository, $security, new Activities(['assets', 'management'])));
    }

    private function can(EstateVoter $voter, User $user, Unit $unit): bool
    {
        return VoterInterface::ACCESS_GRANTED === $voter->vote(new UsernamePasswordToken($user, 'main'), $unit, [EstateVoter::UNIT_PLAN]);
    }

    public function testTheOccupantsWhileTheirLeaseRunsAndNobodyElse(): void
    {
        $company = $this->withId(new Organisation('SCI Test', 'sci-test'));
        $home = $this->withId(new Unit($company, 'Appartement', 'appartement'));
        $other = $this->withId(new Unit($company, 'Studio', 'studio'));
        [$tenant, $cotenant, $guarantor, $former, $neighbour, $stranger] = [$this->user(), $this->user(), $this->user(), $this->user(), $this->user(), $this->user()];

        $lease = $this->withId(new Lease($home, 'L-1', new \DateTimeImmutable('-1 year')));
        $lease->addParty((new LeaseParty('Léa', 'Muller', 'lea@example.org'))->setUser($tenant));
        $lease->addParty((new LeaseParty('Paul', 'Muller', 'paul@example.org', PartyRole::COTENANT))->setUser($cotenant));
        $lease->addParty((new LeaseParty('Anne', 'Muller', 'anne@example.org', PartyRole::GUARANTOR))->setUser($guarantor));
        $home->getLeases()->add($lease);
        $ended = $this->withId((new Lease($home, 'L-0', new \DateTimeImmutable('-4 years')))->setEndOn(new \DateTimeImmutable('-1 year -1 day')));
        $ended->addParty((new LeaseParty('Max', 'Roth', 'max@example.org'))->setUser($former));
        $home->getLeases()->add($ended);
        $next = $this->withId(new Lease($other, 'L-2', new \DateTimeImmutable('-2 months')));
        $next->addParty((new LeaseParty('Karim', 'Benali', 'karim@example.org'))->setUser($neighbour));
        $other->getLeases()->add($next);

        $voter = $this->voter([$lease, $ended, $next]);
        self::assertTrue($this->can($voter, $tenant, $home));
        self::assertTrue($this->can($voter, $cotenant, $home));
        self::assertFalse($this->can($voter, $guarantor, $home), 'a guarantor does not live there');
        self::assertFalse($this->can($voter, $former, $home), 'a lease ended');
        self::assertFalse($this->can($voter, $neighbour, $home), 'another home\'s tenant');
        self::assertTrue($this->can($voter, $neighbour, $other));
        self::assertFalse($this->can($voter, $stranger, $home));
    }

    public function testTheTeamTheAssociatesAndTheOwners(): void
    {
        $company = $this->withId(new Organisation('SCI Test', 'sci-test'));
        $elsewhere = $this->withId(new Organisation('SCI Autre', 'sci-autre'));
        $home = $this->withId(new Unit($company, 'Appartement', 'appartement'));
        [$manager, $associate, $outsider, $owner, $otherOwner] = [$this->user(), $this->user(), $this->user(), $this->user(), $this->user()];
        $members = [(new Member($company, 'Gestion'))->setUser($manager)];
        $associates = [(new Associate($company, 'Marco', 'Meyer'))->setUser($associate), (new Associate($elsewhere, 'Ève', 'Klein'))->setUser($outsider)];
        $hers = $this->withId((new Mandator('Hélène Roth'))->setUser($owner));
        $his = $this->withId((new Mandator('Paul Klein'))->setUser($otherOwner));
        $mandates = [$this->withId((new Mandate($company, $hers))->addUnit($home)), $this->withId(new Mandate($company, $his))];

        $voter = $this->voter([], $members, $associates, [$hers, $his], $mandates);
        self::assertTrue($this->can($voter, $manager, $home), 'the team');
        self::assertTrue($this->can($voter, $associate, $home), 'an associate of its company');
        self::assertFalse($this->can($voter, $outsider, $home), 'another company\'s associate');
        self::assertTrue($this->can($voter, $owner, $home), 'the owner whose mandate holds it');
        self::assertFalse($this->can($voter, $otherOwner, $home), 'another owner');
        self::assertTrue($this->can($this->voter([], admin: true, organisations: [$company, $elsewhere]), $outsider, $home), 'any ROLE_ADMIN');
    }
}
