<?php

namespace Base\Estate\Drive;

use Base\Drive\Share\FileShare;
use Base\Estate\Entity\Mandator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * An owner's folder in the site's cloud (omnibase/drive):
 * Propriétaires/<name>, shared by name with the owner once they have an
 * account, read only - the agency puts in it what is theirs (the mandate,
 * the leases, the diagnostics) and their statements land in its « Comptes
 * rendus ». The folder is the team's (drive.default_audience) and theirs:
 * nobody else sees it.
 *
 * Only loaded when omnibase/drive is installed (config/services.php):
 * estate does not require it, its callers take this service as optional.
 */
class MandatorFolders
{
    public const ROOT = 'Propriétaires';
    public const STATEMENTS = 'Comptes rendus';

    public function __construct(
        private readonly FileShare $share,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%drive.default_audience%')] private readonly string $staff = 'staff',
    ) {
    }

    public function pathOf(Mandator $mandator): string
    {
        return $mandator->getFolder() ?? self::ROOT.'/'.(FileShare::name($mandator->getName()) ?: 'Propriétaire '.$mandator->getId());
    }

    /** The folder made if it is not there, shared read-only with the owner when they have an account; its path. */
    public function sync(Mandator $mandator): string
    {
        $path = $this->pathOf($mandator);
        if (!$this->share->isDirectory(self::ROOT)) {
            $this->share->createDirectory(self::ROOT);
        }
        if (!$this->share->isDirectory($path)) {
            $this->share->createDirectory($path);
            $this->share->createDirectory($path.'/'.self::STATEMENTS);
        }
        // The first time: the team's own (not its parent's audience, which may be wider).
        if (null === $mandator->getFolder()) {
            $this->share->describe($path, $this->staff, sprintf('Documents de %s, propriétaire en gérance : mandat, baux, comptes rendus de gérance.', $mandator->getName()));
        }
        if (null !== $mandator->getUser() && !$this->share->isSharedWith($path, $mandator->getUser())) {
            $this->share->shareWith($path, $mandator->getUser(), false);
        }
        if ($mandator->getFolder() !== $path) {
            $mandator->setFolder($path);
            $this->entityManager->flush();
        }

        return $path;
    }

    /** A statement's PDF, into the folder's « Comptes rendus » (replacing the same period's); its path. */
    public function fileStatement(Mandator $mandator, string $filename, string $pdf): string
    {
        $directory = $this->sync($mandator).'/'.self::STATEMENTS;
        if (!$this->share->isDirectory($directory)) {
            $this->share->createDirectory($directory);
        }
        $path = $directory.'/'.(FileShare::name($filename) ?: 'compte-rendu.pdf');
        $stream = fopen('php://temp', 'r+');
        try {
            fwrite($stream, $pdf);
            rewind($stream);
            $this->share->write($path, $stream);
        } finally {
            \is_resource($stream) && fclose($stream);
        }

        return $path;
    }
}
