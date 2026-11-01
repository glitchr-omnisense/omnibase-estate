<?php

namespace Base\Estate\Drive;

use Base\Drive\Share\FileShare;
use Base\Estate\Entity\Lease;
use Base\Estate\Entity\LeaseParty;
use Base\Estate\Enum\LeaseStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A tenant's folder in the site's cloud (omnibase/drive): Locataires/<name>,
 * one for each tenant and co-tenant of a signed lease (not its guarantors),
 * shared by name with them, read only, once they have an account - the lease,
 * the inventory of fixtures, the receipts. The folder is the team's
 * (drive.default_audience) and theirs: nobody else sees it. A folder already
 * there under that name is reused, what was said of it kept; a tenant on a
 * second lease keeps the same one.
 *
 * Only loaded when omnibase/drive is installed (config/services.php):
 * estate does not require it, its callers take this service as optional.
 */
class LeaseFolders
{
    public const ROOT = 'Locataires';

    public function __construct(
        private readonly FileShare $share,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%drive.default_audience%')] private readonly string $staff = 'staff',
    ) {
    }

    public function pathOf(LeaseParty $party): string
    {
        return $party->getFolder() ?? self::ROOT.'/'.(FileShare::name($party->getName()) ?: 'Locataire '.$party->getId());
    }

    /**
     * Each tenant's folder of a signed lease made if it is not there, shared
     * read-only with those who have an account; their paths. An ended lease
     * makes none.
     *
     * @return list<string>
     */
    public function sync(Lease $lease): array
    {
        if (LeaseStatus::ENDED === $lease->getStatus()) {
            return [];
        }
        $paths = [];
        foreach ($lease->getParties() as $party) {
            if ($party->isOccupant()) {
                $paths[] = $this->syncParty($party);
            }
        }

        return array_values(array_unique($paths));
    }

    /** One tenant's folder made if it is not there, shared read-only with them when they have an account; its path. */
    public function syncParty(LeaseParty $party): string
    {
        $path = $this->pathOf($party);
        if (!$this->share->isDirectory(self::ROOT)) {
            $this->share->createDirectory(self::ROOT);
        }
        if (!$this->share->isDirectory($path)) {
            $this->share->createDirectory($path);
        }
        // The first time: the team's own (not its parent's audience, which may be wider) - or, for a
        // folder made by hand before, what the team said of it.
        if (null === $party->getFolder()) {
            $row = $this->share->row($path);
            $this->share->describe($path, $row?->getAudience() ?? $this->staff, $row?->getDescription() ?? sprintf('Documents de %s, locataire : bail, état des lieux, quittances.', $party->getName()));
        }
        if (null !== $party->getUser() && !$this->share->isSharedWith($path, $party->getUser())) {
            $this->share->shareWith($path, $party->getUser(), false);
        }
        if ($party->getFolder() !== $path) {
            $party->setFolder($path);
            $this->entityManager->flush();
        }

        return $path;
    }
}
