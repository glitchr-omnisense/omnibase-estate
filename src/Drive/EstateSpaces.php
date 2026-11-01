<?php

namespace Base\Estate\Drive;

use Base\Drive\Space\SpaceProviderInterface;
use Base\Entity\User;
use Base\Estate\Entity\Mandator;
use Base\Estate\Entity\OwnershipShare;
use Base\Estate\Enum\Activity;
use Base\Estate\Enum\LeaseStatus;
use Base\Estate\Repository\LeaseRepository;
use Base\Estate\Service\Activities;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Where a person's space is in the site's cloud (omnibase/drive's
 * SpaceProviderInterface), by what they are to the business: a tenant's
 * Locataires/<name> (their lease running, or under notice), an owner's
 * Propriétaires/<name> (with management), a partner's - a co-owner's -
 * Indivisions/<ownership> (with ownership); in that order, the first that
 * applies. The folder is made if it is not there and shared with them, as
 * each business does. Anyone else: none of estate's, the drive's own place.
 *
 * A file shared on upload with someone goes there (omnibase/drive's Spaces).
 * Only loaded when omnibase/drive is installed (config/services.php).
 */
final class EstateSpaces implements SpaceProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LeaseRepository $leases,
        private readonly Activities $activities,
        private readonly LeaseFolders $leaseFolders,
        private readonly MandatorFolders $mandatorFolders,
        private readonly OwnershipFolders $ownershipFolders,
    ) {
    }

    public function spaceOf(User $user): ?string
    {
        if ($this->activities->any(...Activity::RENTAL)) {
            foreach ($this->leases->findForUser($user) as $lease) {
                if (LeaseStatus::ENDED === $lease->getStatus()) {
                    continue;
                }
                foreach ($lease->getParties() as $party) {
                    if ($party->isOccupant() && $party->getUser()?->getId() === $user->getId()) {
                        return $this->leaseFolders->syncParty($party);
                    }
                }
            }
        }
        if ($this->activities->has(Activity::MANAGEMENT)) {
            foreach ($this->entityManager->getRepository(Mandator::class)->findBy(['user' => $user], ['name' => 'ASC']) as $mandator) {
                return $this->mandatorFolders->sync($mandator);
            }
        }
        if ($this->activities->has(Activity::OWNERSHIP)) {
            foreach ($this->entityManager->getRepository(OwnershipShare::class)->findBy(['user' => $user]) as $share) {
                return $this->ownershipFolders->sync($share->getOwnership());
            }
        }

        return null;
    }
}
