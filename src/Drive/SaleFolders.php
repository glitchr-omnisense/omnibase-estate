<?php

namespace Base\Estate\Drive;

use Base\Drive\Share\FileShare;
use Base\Entity\User;
use Base\Estate\Entity\Sale;
use Base\Estate\Entity\SaleMandate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A sale mandate's folder in the site's cloud (omnibase/drive):
 * Transactions/<mandate number>, the team's (drive.default_audience),
 * shared by name, read only, with each seller who has an account and,
 * once an offer is accepted, with its buyer - the diagnostics, the
 * compromis, the acte; each step's document lands in it. A cancelled
 * sale's buyer no longer sees it.
 *
 * Only loaded when omnibase/drive is installed (config/services.php):
 * estate does not require it, its callers take this service as optional.
 */
class SaleFolders
{
    public const ROOT = 'Transactions';

    public function __construct(
        private readonly FileShare $share,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%drive.default_audience%')] private readonly string $staff = 'staff',
    ) {
    }

    public function pathOf(SaleMandate $mandate): string
    {
        return $mandate->getFolder() ?? self::ROOT.'/'.(FileShare::name((string) $mandate->getNumber()) ?: 'Mandat de vente '.$mandate->getId());
    }

    /**
     * The folder made if it is not there, shared read-only with the sellers
     * who have an account and with the buyer of $sale (the accepted offer's)
     * when it is live; its path.
     */
    public function sync(SaleMandate $mandate, ?Sale $sale = null): string
    {
        $path = $this->pathOf($mandate);
        if (!$this->share->isDirectory(self::ROOT)) {
            $this->share->createDirectory(self::ROOT);
        }
        if (!$this->share->isDirectory($path)) {
            $this->share->createDirectory($path);
        }
        // The first time: the team's own (not its parent's audience, which may be wider).
        if (null === $mandate->getFolder()) {
            $this->share->describe($path, $this->staff, sprintf('Vente %s : %s. Diagnostics, compromis, acte.', $mandate->getNumber(), $mandate->getPropertyName()));
        }
        $users = array_filter(array_map(fn ($seller) => $seller->getUser(), $mandate->getSellers()->toArray()));
        if (null !== $sale && $sale->getStep()->isLive() && null !== $sale->getBuyer()->getUser()) {
            $users[] = $sale->getBuyer()->getUser();
        }
        foreach ($users as $user) {
            if (!$this->share->isSharedWith($path, $user)) {
                $this->share->shareWith($path, $user, false);
            }
        }
        if ($mandate->getFolder() !== $path) {
            $mandate->setFolder($path);
            $this->entityManager->flush();
        }

        return $path;
    }

    /** Someone no longer let in (a cancelled sale's buyer). */
    public function unshare(SaleMandate $mandate, User $user): void
    {
        $row = $this->share->row($this->pathOf($mandate));
        if (null === $row || !$row->hasMember($user)) {
            return;
        }
        $row->removeMember($user);
        $this->entityManager->flush();
    }

    /** A step's document into the folder (replacing a file of that name); its path. */
    public function file(SaleMandate $mandate, string $filename, string $contents): string
    {
        $path = $this->sync($mandate).'/'.(FileShare::name($filename) ?: 'document.pdf');
        $stream = fopen('php://temp', 'r+');
        try {
            fwrite($stream, $contents);
            rewind($stream);
            $this->share->write($path, $stream);
        } finally {
            \is_resource($stream) && fclose($stream);
        }

        return $path;
    }
}
