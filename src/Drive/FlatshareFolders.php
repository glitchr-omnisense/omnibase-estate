<?php

namespace Base\Estate\Drive;

use Base\Drive\Entity\ShareGroup;
use Base\Drive\Share\FileShare;
use Base\Entity\User;
use Base\Estate\Entity\Lease;
use Base\Estate\Enum\LeaseStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A flatshare's place in the site's cloud (omnibase/drive). A signed lease with two occupants or
 * more (Lease::isShared(): its tenant and co-tenants, not its guarantors - a couple too) gets:
 *
 * - a group of people, « Colocation <home> » (omnibase/drive's ShareGroup), whose members are its
 *   occupants who have an account, kept so as they are added, taken off, or accept their
 *   invitation. The group is the lease's: someone the team adds to it by hand is taken off at the
 *   next sync (share the folder with them by name instead); renamed by the team, it keeps its
 *   new name (the lease knows it by its id);
 * - a common folder, Colocations/<home>, the team's (drive.default_audience), shared read-only
 *   with that group: the documents of the whole flatshare - the lease, the inventory of fixtures,
 *   the house rules. Each occupant keeps their own Locataires/<name> (LeaseFolders).
 *
 * When the lease ends, the group loses its members and the folder stays with what it holds, as a
 * tenant's folder does. A later lease of the same home gets a folder of its own,
 * « Colocations/<home> - <reference> », never the former flatmates' papers. A lease that loses
 * an occupant keeps its folder and group, with the ones left.
 *
 * Only loaded when omnibase/drive is installed (config/services.php): LeaseFolders::sync() calls
 * it, so wherever a lease's folders are made (the lease and its parties saved, an invitation
 * accepted) the flatshare's are too.
 */
class FlatshareFolders
{
    public const ROOT = 'Colocations';

    public function __construct(
        private readonly FileShare $share,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%drive.default_audience%')] private readonly string $staff = 'staff',
    ) {
    }

    /** « Colocation <home> », the label of a couple's too. */
    public static function groupName(Lease $lease): string
    {
        return mb_substr('Colocation '.$lease->getUnit()->getName(), 0, 120);
    }

    /** Its folder: the one it has, else Colocations/<home> - or, when another lease has that one, with its own reference. */
    public function pathOf(Lease $lease): string
    {
        if (null !== $lease->getFolder()) {
            return $lease->getFolder();
        }
        $path = self::ROOT.'/'.(FileShare::name($lease->getUnit()->getName()) ?: 'Logement '.$lease->getUnit()->getId());
        $holder = $this->entityManager->getRepository(Lease::class)->findOneBy(['folder' => $path]);
        if (null !== $holder && $holder !== $lease) {
            $path .= ' - '.(FileShare::name($lease->getReference()) ?: 'bail '.$lease->getId());
        }

        return $path;
    }

    /** Its group of occupants, once made (and while the team has not deleted it). */
    public function groupOf(Lease $lease): ?ShareGroup
    {
        return null !== $lease->getGroupId() ? $this->entityManager->find(ShareGroup::class, $lease->getGroupId()) : null;
    }

    /**
     * The flatshare's folder and group: made the first time the signed lease has two occupants,
     * the group's members its occupants with an account from then on - nobody once the lease has
     * ended. The folder's path; null for a lease that never was a flatshare.
     */
    public function sync(Lease $lease): ?string
    {
        $group = $this->groupOf($lease);
        $ended = LeaseStatus::ENDED === $lease->getStatus();
        if (null === $lease->getFolder() && null === $group && ($ended || !$lease->isShared())) {
            return null;
        }
        if ($ended) {
            if (null !== $group && $group->getMembers()->count()) {
                $this->members($group, []);
                $this->entityManager->flush();
            }

            return $lease->getFolder();
        }

        $path = $this->pathOf($lease);
        if (!$this->share->isDirectory(self::ROOT)) {
            $this->share->createDirectory(self::ROOT);
        }
        if (!$this->share->isDirectory($path)) {
            $this->share->createDirectory($path);
        }
        // The first time: the team's own (not its parent's audience, which may be wider) - or, for a
        // folder made by hand before, what the team said of it.
        if (null === $lease->getFolder()) {
            $row = $this->share->row($path);
            $this->share->describe($path, $row?->getAudience() ?? $this->staff, $row?->getDescription()
                ?? sprintf('Documents communs de la colocation (bail %s) : le bail, l\'état des lieux, le règlement intérieur.', $lease->getReference()));
        }

        $group ??= $this->newGroup($lease);
        $occupants = [];
        foreach ($lease->getTenants() as $party) {
            if (null !== $party->getUser()) {
                $occupants[] = $party->getUser();
            }
        }
        $this->members($group, $occupants);
        $this->entityManager->persist($group);
        $this->entityManager->flush();

        if (!$this->share->row($path)?->getGroups()->contains($group)) {
            $this->share->shareWithGroup($path, $group, false);
        }
        if ($lease->getFolder() !== $path || $lease->getGroupId() !== $group->getId()) {
            $lease->setFolder($path)->setGroupId($group->getId());
            $this->entityManager->flush();
        }

        return $path;
    }

    /** A group named so already, made by the team and held by no lease, is taken; else a new one. */
    private function newGroup(Lease $lease): ShareGroup
    {
        $name = self::groupName($lease);
        foreach ($this->entityManager->getRepository(ShareGroup::class)->findBy(['name' => $name]) as $group) {
            if (null === $this->entityManager->getRepository(Lease::class)->findOneBy(['groupId' => $group->getId()])) {
                return $group;
            }
        }

        return new ShareGroup($name);
    }

    /** @param list<User> $users the group's members, exactly */
    private function members(ShareGroup $group, array $users): void
    {
        $ids = array_map(fn (User $user) => $user->getId(), $users);
        foreach ($group->getMembers()->toArray() as $member) {
            if (!\in_array($member->getId(), $ids, true)) {
                $group->removeMember($member);
            }
        }
        foreach ($users as $user) {
            $group->addMember($user);
        }
    }
}
