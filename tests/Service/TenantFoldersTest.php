<?php

namespace Tests\Base\Estate\Service;

use Base\Drive\Entity\SharedPath;
use Base\Drive\Repository\ShareDownloadRepository;
use Base\Drive\Repository\SharedPathRepository;
use Base\Drive\Share\FileShare;
use Base\Entity\User;
use Base\Estate\Drive\EstateSpaces;
use Base\Estate\Drive\LeaseFolders;
use Base\Estate\Drive\MandatorFolders;
use Base\Estate\Drive\OwnershipFolders;
use Base\Estate\Entity\Lease;
use Base\Estate\Entity\LeaseParty;
use Base\Estate\Entity\Mandator;
use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\Unit;
use Base\Estate\Enum\LeaseStatus;
use Base\Estate\Enum\PartyRole;
use Base\Estate\Repository\LeaseRepository;
use Base\Estate\Service\Activities;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;

/**
 * A signed lease gives each of its tenants a folder in the cloud
 * (omnibase/drive): Locataires/<name>, shared by name, read only, once they
 * have an account - as an owner's folder is under a management mandate. The
 * tenant's folder is their space too: a file the team shares with them on
 * upload goes there (Drive\EstateSpaces).
 */
final class TenantFoldersTest extends TestCase
{
    private string $directory;
    private Filesystem $storage;
    /** @var array<string, SharedPath> */
    private array $rows = [];
    private FileShare $share;
    private EntityManagerInterface $entityManager;
    /** @var list<Mandator> */
    private array $mandators = [];

    protected function setUp(): void
    {
        if (!class_exists(FileShare::class)) {
            self::markTestSkipped('omnibase/drive is needed.');
        }
        $this->directory = sys_get_temp_dir().'/estate-drive-'.bin2hex(random_bytes(4));
        $this->storage = new Filesystem(new LocalFilesystemAdapter($this->directory));
        $this->entityManager = $this->createStub(EntityManagerInterface::class);
        $this->entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof SharedPath) {
                $this->rows[$entity->getPath()] = $entity;
            }
        });
        $this->entityManager->method('getRepository')->willReturnCallback(function (string $class) {
            $repository = $this->createStub(EntityRepository::class);
            $repository->method('findBy')->willReturnCallback(fn (array $criteria) => Mandator::class === $class
                ? array_values(array_filter($this->mandators, fn (Mandator $m) => $m->getUser() === ($criteria['user'] ?? null)))
                : []);

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

    public function testASignedLeaseGivesEachTenantAFolderSharedOnceTheyHaveAnAccount(): void
    {
        $lea = self::user(41);
        [$lease, $leaParty, $tomParty] = $this->lease($lea);
        $folders = new LeaseFolders($this->share, $this->entityManager);

        self::assertSame(['Locataires/Léa Muller', 'Locataires/Tom Krebs'], $folders->sync($lease), 'the tenant and the co-tenant, not the guarantor');
        self::assertTrue($this->share->isDirectory('Locataires/Léa Muller'));
        self::assertFalse($this->share->isDirectory('Locataires/Jean Muller'));
        self::assertSame('Locataires/Léa Muller', $leaParty->getFolder(), 'kept on the party');
        self::assertSame('staff', $this->share->audience('Locataires/Léa Muller'), 'the team\'s');
        self::assertStringContainsString('Léa Muller', (string) $this->share->row('Locataires/Léa Muller')->getDescription());
        self::assertTrue($this->share->isSharedWith('Locataires/Léa Muller/bail.pdf', $lea), 'shared by name with her account');
        self::assertFalse($this->share->row('Locataires/Léa Muller')->allowsDeposit(), 'read only');
        self::assertFalse($this->share->isSharedWith('Locataires/Tom Krebs', $lea), 'not someone else\'s');
        self::assertFalse((bool) $this->share->row('Locataires/Tom Krebs')->isWorkspace(), 'Tom has no account yet: shared with nobody');

        // Tom accepts his invitation: his folder is his, the one already made.
        $tom = self::user(42);
        $tomParty->setUser($tom);
        $before = $this->share->directories();
        $folders->sync($lease);
        self::assertTrue($this->share->isSharedWith('Locataires/Tom Krebs', $tom));
        self::assertSame($before, $this->share->directories(), 'nothing made twice');

        // An ended lease makes none.
        $old = (new Lease($lease->getUnit(), 'MA-2019-001', new \DateTimeImmutable('2019-01-01')))->setStatus(LeaseStatus::ENDED);
        $old->addParty(new LeaseParty('Paul', 'Klein', 'paul@example.org'));
        self::assertSame([], $folders->sync($old));
        self::assertFalse($this->share->isDirectory('Locataires/Paul Klein'));
    }

    public function testAFolderMadeByHandBeforeIsReusedWithWhatWasSaidOfIt(): void
    {
        $this->storage->write('Locataires/Léa Muller/Quittance octobre.pdf', '%PDF');
        $this->share->describe('Locataires/Léa Muller', 'staff', 'Ses papiers, rangés par la gestion.');
        [$lease, $leaParty] = $this->lease(self::user(41));

        (new LeaseFolders($this->share, $this->entityManager))->sync($lease);

        self::assertSame('Ses papiers, rangés par la gestion.', $this->share->row('Locataires/Léa Muller')->getDescription());
        self::assertTrue($this->storage->fileExists('Locataires/Léa Muller/Quittance octobre.pdf'));
        self::assertSame('Locataires/Léa Muller', $leaParty->getFolder());
    }

    public function testATenantsSpaceIsTheirFolderAnOwnersTheirsAndAnyoneElseNoneOfEstates(): void
    {
        $lea = self::user(41);
        [$lease] = $this->lease($lea);
        $helene = self::user(43);
        $this->mandators[] = (new Mandator('Hélène Roth'))->setUser($helene);
        $leases = $this->createStub(LeaseRepository::class);
        $leases->method('findForUser')->willReturnCallback(fn (User $user) => $user === $lea ? [$lease] : []);
        $spaces = fn (array $activities) => new EstateSpaces($this->entityManager, $leases, new Activities($activities),
            new LeaseFolders($this->share, $this->entityManager), new MandatorFolders($this->share, $this->entityManager), new OwnershipFolders($this->share, $this->entityManager));

        self::assertSame('Locataires/Léa Muller', $spaces(['assets'])->spaceOf($lea), 'made the first time she is shared something');
        self::assertTrue($this->share->isSharedWith('Locataires/Léa Muller', $lea));
        self::assertSame('Propriétaires/Hélène Roth', $spaces(['management'])->spaceOf($helene));
        self::assertNull($spaces(['assets', 'ownership'])->spaceOf($helene), 'no owner without management');
        self::assertNull($spaces(['assets', 'management'])->spaceOf(self::user(44)), 'nobody to estate');
    }

    /** @return array{0: Lease, 1: LeaseParty, 2: LeaseParty} Léa Muller's signed lease in A, with Tom Krebs and her father as guarantor */
    private function lease(User $lea): array
    {
        $unit = new Unit(new Organisation('SCI Exemple'), 'A T2');
        $lease = new Lease($unit, 'MA-2026-007', new \DateTimeImmutable('2026-09-01'));
        $lease->addParty($leaParty = (new LeaseParty('Léa', 'Muller', 'lea@example.org'))->setUser($lea));
        $lease->addParty($tomParty = new LeaseParty('Tom', 'Krebs', 'tom@example.org', PartyRole::COTENANT));
        $lease->addParty(new LeaseParty('Jean', 'Muller', 'jean@example.org', PartyRole::GUARANTOR));

        return [$lease, $leaParty, $tomParty];
    }

    private static function user(int $id): User
    {
        $user = (new \ReflectionClass(User::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }
}
