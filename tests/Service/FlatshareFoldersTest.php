<?php

namespace Tests\Base\Estate\Service;

use Base\Drive\Entity\SharedPath;
use Base\Drive\Entity\ShareGroup;
use Base\Drive\Repository\ShareDownloadRepository;
use Base\Drive\Repository\SharedPathRepository;
use Base\Drive\Share\FileShare;
use Base\Entity\User;
use Base\Estate\Drive\FlatshareFolders;
use Base\Estate\Drive\LeaseFolders;
use Base\Estate\Entity\Lease;
use Base\Estate\Entity\LeaseParty;
use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\Unit;
use Base\Estate\Enum\LeaseStatus;
use Base\Estate\Enum\PartyRole;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;

/**
 * A lease with two occupants or more is a flatshare (a couple too): with omnibase/drive, a group
 * « Colocation <home> » of its occupants who have an account, following them as they come, go
 * and accept their invitation, and a common folder Colocations/<home> shared read-only with it.
 * A single tenant gets neither; an ended lease's group loses its members, its folder stays.
 */
final class FlatshareFoldersTest extends TestCase
{
    private string $directory;
    private Filesystem $storage;
    /** @var array<string, SharedPath> */
    private array $rows = [];
    /** @var array<int, ShareGroup> */
    private array $groups = [];
    /** @var list<Lease> */
    private array $leases = [];
    private FileShare $share;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        if (!class_exists(ShareGroup::class)) {
            self::markTestSkipped('omnibase/drive, with its groups, is needed.');
        }
        $this->directory = sys_get_temp_dir().'/estate-flatshare-'.bin2hex(random_bytes(4));
        $this->storage = new Filesystem(new LocalFilesystemAdapter($this->directory));
        $this->entityManager = $this->createStub(EntityManagerInterface::class);
        $this->entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof SharedPath) {
                $this->rows[$entity->getPath()] = $entity;
            } elseif ($entity instanceof ShareGroup && null === $entity->getId()) {
                $id = \count($this->groups) + 1;
                (new \ReflectionProperty(ShareGroup::class, 'id'))->setValue($entity, $id);
                $this->groups[$id] = $entity;
            }
        });
        $this->entityManager->method('find')->willReturnCallback(fn (string $class, mixed $id) => ShareGroup::class === $class ? ($this->groups[$id] ?? null) : null);
        $this->entityManager->method('getRepository')->willReturnCallback(function (string $class) {
            $repository = $this->createStub(EntityRepository::class);
            $matches = fn (array $criteria) => match ($class) {
                Lease::class => array_values(array_filter($this->leases, fn (Lease $l) => (!\array_key_exists('folder', $criteria) || $l->getFolder() === $criteria['folder'])
                    && (!\array_key_exists('groupId', $criteria) || $l->getGroupId() === $criteria['groupId']))),
                ShareGroup::class => array_values(array_filter($this->groups, fn (ShareGroup $g) => $g->getName() === ($criteria['name'] ?? null))),
                default => [],
            };
            $repository->method('findBy')->willReturnCallback($matches);
            $repository->method('findOneBy')->willReturnCallback(fn (array $criteria) => $matches($criteria)[0] ?? null);

            return $repository;
        });
        $paths = $this->createStub(SharedPathRepository::class);
        $paths->method('byPath')->willReturnCallback(fn () => $this->rows);
        $downloads = $this->createStub(ShareDownloadRepository::class);
        $downloads->method('counts')->willReturn([]);
        $this->share = new FileShare($this->storage, $paths, $this->entityManager, $downloads);
    }

    protected function tearDown(): void
    {
        if (isset($this->storage)) {
            $this->storage->deleteDirectory('');
            @rmdir($this->directory);
        }
    }

    public function testAFlatshareHasItsGroupAndCommonFolderAndTheGroupFollowsItsOccupants(): void
    {
        $lea = self::user(41);
        $ines = self::user(43);
        $jean = self::user(49);
        $unit = new Unit(new Organisation('Meyers & Associée'), 'Grand appartement Krutenau');
        $lease = $this->lease($unit, 'MA-2026-010');
        $lease->addParty((new LeaseParty('Léa', 'Muller', 'lea@example.org'))->setUser($lea));
        $lease->addParty($tom = new LeaseParty('Tom', 'Krebs', 'tom@example.org', PartyRole::COTENANT));
        $lease->addParty($inesParty = (new LeaseParty('Inès', 'Haddad', 'ines@example.org', PartyRole::COTENANT))->setUser($ines));
        $lease->addParty((new LeaseParty('Jean', 'Muller', 'jean@example.org', PartyRole::GUARANTOR))->setUser($jean));
        $folders = $this->leaseFolders();

        self::assertSame(['Locataires/Léa Muller', 'Locataires/Tom Krebs', 'Locataires/Inès Haddad'], $folders->sync($lease), 'each keeps their own folder');
        $common = 'Colocations/Grand appartement Krutenau';
        self::assertTrue($this->share->isDirectory($common));
        self::assertSame($common, $lease->getFolder(), 'kept on the lease');
        self::assertSame('staff', $this->share->audience($common), 'the team\'s');
        self::assertStringContainsString('le bail, l\'état des lieux, le règlement intérieur', (string) $this->share->row($common)->getDescription());
        $group = $this->groups[$lease->getGroupId()];
        self::assertSame('Colocation Grand appartement Krutenau', $group->getName());
        self::assertSame([41, 43], self::ids($group), 'the occupants with an account, not the guarantor');
        self::assertTrue($this->share->row($common)->getGroups()->contains($group), 'shared with the group');
        self::assertFalse($this->share->row($common)->allowsDeposit(), 'read only');
        self::assertTrue($this->share->isSharedWith($common.'/Bail.pdf', $lea));
        self::assertTrue($this->share->isSharedWith($common, $ines));
        self::assertFalse($this->share->isSharedWith($common, $jean), 'the guarantor is no flatmate');
        self::assertFalse($this->share->isSharedWith('Locataires/Inès Haddad', $lea), 'each one\'s own folder stays theirs');

        // Tom accepts his invitation: he is in the group, nothing is made twice.
        $before = $this->share->directories();
        $tom->setUser($tom = self::user(42));
        $folders->sync($lease);
        self::assertSame([41, 42, 43], self::ids($group));
        self::assertTrue($this->share->isSharedWith($common, $tom));
        self::assertSame($before, $this->share->directories());
        self::assertCount(1, $this->groups, 'one group');

        // The team renames the group; Inès moves out: she is out of it, it keeps its name.
        $group->setName('Coloc des Orphelins');
        $lease->removeParty($inesParty);
        $folders->sync($lease);
        self::assertSame([41, 42], self::ids($group));
        self::assertFalse($this->share->isSharedWith($common, $ines));
        self::assertSame('Coloc des Orphelins', $group->getName());

        // The lease ends: the group loses its members, the folder stays with what it holds.
        $this->storage->write($common.'/Bail.pdf', '%PDF');
        $lease->setStatus(LeaseStatus::ENDED);
        $folders->sync($lease);
        self::assertSame([], self::ids($group));
        self::assertFalse($this->share->isSharedWith($common, $lea));
        self::assertTrue($this->storage->fileExists($common.'/Bail.pdf'));

        // The next flatshare in that home: a folder and a group of its own, not the former flatmates' papers.
        $next = $this->lease($unit, 'MA-2027-002');
        $next->addParty((new LeaseParty('Nora', 'Klein', 'nora@example.org'))->setUser($nora = self::user(51)));
        $next->addParty(new LeaseParty('Hugo', 'Roth', 'hugo@example.org', PartyRole::COTENANT));
        $folders->sync($next);
        self::assertSame($common.' - MA-2027-002', $next->getFolder());
        self::assertNotSame($lease->getGroupId(), $next->getGroupId());
        self::assertSame([51], self::ids($this->groups[$next->getGroupId()]));
        self::assertFalse($this->share->isSharedWith($common, $nora));
    }

    public function testASingleTenantGetsNeitherAndACoupleIsAFlatshareToo(): void
    {
        $organisation = new Organisation('Meyers & Associée');
        $single = $this->lease(new Unit($organisation, 'Appartement Neudorf'), 'MA-2024-002');
        $single->addParty((new LeaseParty('Karim', 'Benali', 'karim@example.org'))->setUser(self::user(61)));
        $single->addParty(new LeaseParty('Samia', 'Benali', 'samia@example.org', PartyRole::GUARANTOR));
        $flatshares = new FlatshareFolders($this->share, $this->entityManager);

        self::assertNull($flatshares->sync($single), 'one occupant (and a guarantor): no flatshare');
        $this->leaseFolders()->sync($single);
        self::assertFalse($this->share->isDirectory(FlatshareFolders::ROOT));
        self::assertSame([], $this->groups);
        self::assertNull($single->getFolder());

        // A couple on one lease: two occupants, the same mechanism, the label « Colocation » too.
        $couple = $this->lease(new Unit($organisation, 'Maison de la Robertsau'), 'MA-2021-001');
        $couple->addParty(new LeaseParty('Thomas', 'Weber', 'thomas@example.org'));
        $couple->addParty((new LeaseParty('Julie', 'Weber', 'julie@example.org', PartyRole::COTENANT))->setUser(self::user(62)));
        self::assertSame('Colocations/Maison de la Robertsau', $flatshares->sync($couple));
        self::assertSame('Colocation Maison de la Robertsau', $flatshares->groupOf($couple)->getName());
        self::assertSame([62], self::ids($flatshares->groupOf($couple)));

        // An ended lease that never was one makes none.
        $old = $this->lease(new Unit($organisation, 'Studio Krutenau'), 'MA-2019-001')->setStatus(LeaseStatus::ENDED);
        $old->addParty(new LeaseParty('Paul', 'Klein', 'paul@example.org'));
        $old->addParty(new LeaseParty('Anna', 'Klein', 'anna@example.org', PartyRole::COTENANT));
        self::assertNull($flatshares->sync($old));
        self::assertFalse($this->share->isDirectory('Colocations/Studio Krutenau'));
    }

    public function testAGroupTheTeamMadeUnderThatNameIsTaken(): void
    {
        $made = new ShareGroup('Colocation Grand appartement Krutenau');
        $this->entityManager->persist($made);
        $lease = $this->lease(new Unit(new Organisation('Meyers & Associée'), 'Grand appartement Krutenau'), 'MA-2026-010');
        $lease->addParty((new LeaseParty('Léa', 'Muller', 'lea@example.org'))->setUser(self::user(41)));
        $lease->addParty((new LeaseParty('Inès', 'Haddad', 'ines@example.org', PartyRole::COTENANT))->setUser(self::user(43)));

        (new FlatshareFolders($this->share, $this->entityManager))->sync($lease);

        self::assertSame($made->getId(), $lease->getGroupId());
        self::assertSame([41, 43], self::ids($made));
        self::assertCount(1, $this->groups);
    }

    private function leaseFolders(): LeaseFolders
    {
        return new LeaseFolders($this->share, $this->entityManager, 'staff', new FlatshareFolders($this->share, $this->entityManager));
    }

    private function lease(Unit $unit, string $reference): Lease
    {
        $this->leases[] = $lease = new Lease($unit, $reference, new \DateTimeImmutable('2026-09-01'));

        return $lease;
    }

    /** @return list<int> */
    private static function ids(ShareGroup $group): array
    {
        $ids = array_map(fn (User $user) => $user->getId(), $group->getMembers()->toArray());
        sort($ids);

        return array_values($ids);
    }

    private static function user(int $id): User
    {
        $user = (new \ReflectionClass(User::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }
}
