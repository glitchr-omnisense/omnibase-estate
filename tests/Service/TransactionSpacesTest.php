<?php

namespace Tests\Base\Estate\Service;

use Base\Entity\User;
use Base\Estate\Entity\Buyer;
use Base\Estate\Entity\Mandator;
use Base\Estate\Entity\Offer;
use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\Sale;
use Base\Estate\Entity\SaleEvent;
use Base\Estate\Entity\SaleMandate;
use Base\Estate\Entity\Visit;
use Base\Estate\Enum\SaleStep;
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
 * The seller's and the buyer's spaces exist only while the site runs
 * `transaction`: off, nobody is either and the sales' tables are not read;
 * on, a seller sees their mandate, its visits, offers and sale, a buyer
 * their offer and its sale - nobody else's, and the buyer not the
 * mandate's other visits.
 */
final class TransactionSpacesTest extends TestCase
{
    /** @var list<class-string> */
    private array $read = [];

    private function spaces(array $activities, array $records): Spaces
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(function (string $class) use ($records) {
            $this->read[] = $class;
            $repository = $this->createStub(EntityRepository::class);
            $repository->method('findBy')->willReturnCallback(fn (array $criteria) => array_values(array_filter($records[$class] ?? [],
                fn (object $record) => !isset($criteria['user']) || $record->getUser() === $criteria['user'])));

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

    private static function user(int $id): User
    {
        return self::withId((new \ReflectionClass(User::class))->newInstanceWithoutConstructor(), $id);
    }

    public function testTheActivityOffShowsNoSellerNorBuyerSpace(): void
    {
        $user = self::user(41);
        $seller = (new Mandator('Hélène Roth'))->setUser($user);
        $spaces = $this->spaces(['assets', 'ownership'], [Mandator::class => [$seller], SaleMandate::class => [(new SaleMandate(new Organisation('Agence Kléber')))->addSeller($seller)], Buyer::class => [(new Buyer('Lucas Martin'))->setUser($user)]]);

        self::assertSame([], $spaces->saleMandates($user));
        self::assertSame([], $spaces->buyers($user));
        self::assertSame([], $spaces->of($user));
        self::assertSame([], array_intersect([Mandator::class, SaleMandate::class, Buyer::class], $this->read), 'the sales\' tables are not read');
    }

    public function testASellerAndABuyerSeeTheirOwnAndNobodyElses(): void
    {
        $sellerUser = self::user(41);
        $buyerUser = self::user(42);
        $stranger = self::user(43);
        $agency = self::withId(new Organisation('Agence Kléber'), 1);
        $helene = self::withId((new Mandator('Hélène Roth'))->setUser($sellerUser), 5);
        $paul = self::withId((new Mandator('Paul Klein'))->setUser($stranger), 6);
        $hers = self::withId((new SaleMandate($agency))->setNumber('VT-2026-0001')->addSeller($helene), 9);
        $his = self::withId((new SaleMandate($agency))->setNumber('VT-2026-0002')->addSeller($paul), 10);
        $lucas = self::withId((new Buyer('Lucas Martin'))->setUser($buyerUser), 7);
        $ines = self::withId(new Buyer('Inès Roux'), 8);
        $offer = self::withId(new Offer($hers, $lucas, 30000000), 20);
        $otherOffer = self::withId(new Offer($hers, $ines, 29000000), 21);
        $sale = self::withId(new Sale($offer), 30);
        $event = (new SaleEvent(SaleStep::COMPROMISE, new \DateTimeImmutable('2026-10-20')))->setSale($sale);
        $spaces = $this->spaces(['transaction'], [Mandator::class => [$helene, $paul], SaleMandate::class => [$hers, $his], Buyer::class => [$lucas, $ines]]);

        self::assertSame([['key' => 'seller', 'route' => 'estate_seller']], $spaces->of($sellerUser));
        self::assertSame([$hers], $spaces->saleMandates($sellerUser));
        self::assertSame([['key' => 'buyer', 'route' => 'estate_buyer']], $spaces->of($buyerUser));

        $voter = new EstateVoter($spaces);
        $vote = fn (User $user, object $subject) => $voter->vote(new UsernamePasswordToken($user, 'main'), $subject, [EstateVoter::SALE_VIEW]);
        foreach ([$hers, new Visit($hers, $ines), $offer, $otherOffer, $sale, $event] as $subject) {
            self::assertSame(VoterInterface::ACCESS_GRANTED, $vote($sellerUser, $subject), 'the seller: '.$subject::class);
        }
        self::assertSame(VoterInterface::ACCESS_DENIED, $vote($sellerUser, $his), 'not another seller\'s mandate');
        foreach ([$offer, $sale, $event] as $subject) {
            self::assertSame(VoterInterface::ACCESS_GRANTED, $vote($buyerUser, $subject), 'the buyer: '.$subject::class);
        }
        foreach ([$hers, $otherOffer, new Visit($hers, $ines)] as $subject) {
            self::assertSame(VoterInterface::ACCESS_DENIED, $vote($buyerUser, $subject), 'not the mandate itself, nor another buyer\'s offer or visit: '.$subject::class);
        }
        self::assertSame(VoterInterface::ACCESS_DENIED, $vote($stranger, $sale), 'another seller sees nothing of this sale');
    }
}
