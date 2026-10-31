<?php

namespace Base\Estate\Drive;

use Base\Database\Attribute\Uploader;
use Base\Drive\Share\FileShare;
use Base\Estate\Entity\Ownership;
use Base\Estate\Entity\OwnershipInvoice;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\File;

/**
 * A shared ownership's folder in the site's cloud (omnibase/drive):
 * Indivisions/<name>, shared by name with each co-owner who has an account,
 * who may bring files into it (an invoice they paid, a quote) and delete
 * none; the invoices posted land in its « Factures ». The folder is the
 * team's (drive.default_audience) and theirs: nobody else sees it.
 *
 * Only loaded when omnibase/drive is installed (config/services.php):
 * estate does not require it, its callers take this service as optional.
 */
class OwnershipFolders
{
    public const ROOT = 'Indivisions';
    public const INVOICES = 'Factures';

    public function __construct(
        private readonly FileShare $share,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%drive.default_audience%')] private readonly string $staff = 'staff',
    ) {
    }

    public function pathOf(Ownership $ownership): string
    {
        return $ownership->getFolder() ?? self::ROOT.'/'.(FileShare::name($ownership->getName()) ?: 'Indivision '.$ownership->getId());
    }

    /** The folder made if it is not there, shared with every co-owner who has an account; its path. */
    public function sync(Ownership $ownership): string
    {
        $path = $this->pathOf($ownership);
        if (!$this->share->isDirectory(self::ROOT)) {
            $this->share->createDirectory(self::ROOT);
        }
        if (!$this->share->isDirectory($path)) {
            $this->share->createDirectory($path);
            $this->share->createDirectory($path.'/'.self::INVOICES);
        }
        // The first time: the team's own (not its parent's audience, which may be wider), the co-owners by name.
        if (null === $ownership->getFolder()) {
            $this->share->describe($path, $this->staff, sprintf('Factures et documents de l\'indivision « %s », partagés avec ses indivisaires.', $ownership->getName()));
        }
        foreach ($ownership->getShares() as $share) {
            if (null !== $share->getUser() && !$this->share->isSharedWith($path, $share->getUser())) {
                $this->share->shareWith($path, $share->getUser(), true);
            }
        }
        if ($ownership->getFolder() !== $path) {
            $ownership->setFolder($path);
            $this->entityManager->flush();
        }

        return $path;
    }

    /** A posted invoice's file, into the folder's « Factures » (beside one of the same name, never over it); its path, or null without a file. */
    public function file(OwnershipInvoice $invoice): ?string
    {
        $file = Uploader::get($invoice, 'file');
        if (!$file instanceof File || !$file->isFile()) {
            return null;
        }
        $directory = $this->sync($invoice->getOwnership()).'/'.self::INVOICES;
        if (!$this->share->isDirectory($directory)) {
            $this->share->createDirectory($directory);
        }
        $extension = $file->guessExtension() ?: $file->getExtension() ?: 'pdf';
        $base = FileShare::name(sprintf('%s %s', $invoice->getDate()->format('Y-m-d'), $invoice->getLabel())) ?: 'facture';
        $path = $directory.'/'.$base.'.'.$extension;
        for ($n = 2; $this->share->isFile($path); ++$n) {
            $path = sprintf('%s/%s (%d).%s', $directory, $base, $n, $extension);
        }
        $stream = fopen($file->getPathname(), 'r');
        try {
            $this->share->write($path, $stream);
        } finally {
            \is_resource($stream) && fclose($stream);
        }

        return $path;
    }
}
