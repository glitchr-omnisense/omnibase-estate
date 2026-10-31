<?php

namespace Base\Estate\Service;

use Base\Entity\User;
use Base\Estate\Entity\Associate;
use Base\Estate\Entity\Contractor;
use Base\Estate\Entity\Lease;
use Base\Estate\Entity\Mandate;
use Base\Estate\Entity\Mandator;
use Base\Estate\Entity\Member;
use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\Ownership;
use Base\Estate\Entity\OwnershipShare;
use Base\Estate\Enum\Activity;
use Base\Estate\Repository\LeaseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Who someone is to the organisations: their management (a Member, or any
 * ROLE_ADMIN), an associate, a tenant (a party of a lease), a contractor,
 * a co-owner of a shared ownership (its share's account; only while the
 * site runs the `ownership` activity), an owner whose homes the agency
 * manages (only while it runs `management`).
 * What each space shows, and what EstateVoter allows, comes from here -
 * from the relations, never from a role stored on the account.
 */
class Spaces implements ResetInterface
{
    /** @var array<string, mixed> per user id, for the request */
    private array $memo = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LeaseRepository $leases,
        private readonly Security $security,
        private readonly ?Activities $activities = null,
    ) {
    }

    /** @return list<Organisation> those whose back office they run */
    public function managed(?User $user): array
    {
        if (!$user) {
            return [];
        }
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return $this->entityManager->getRepository(Organisation::class)->findBy([], ['name' => 'ASC']);
        }

        return $this->memo['managed'.$user->getId()] ??= array_map(fn (Member $m) => $m->getOrganisation(), $this->entityManager->getRepository(Member::class)->findBy(['user' => $user]));
    }

    public function manages(?User $user, Organisation $organisation): bool
    {
        return \in_array($organisation->getId(), array_map(fn (Organisation $o) => $o->getId(), $this->managed($user)), true);
    }

    /** Forgotten between requests (a worker, the tests): who someone is may change. */
    public function reset(): void
    {
        $this->memo = [];
    }

    /** @return list<Associate> */
    public function associateships(?User $user): array
    {
        return $user ? $this->memo['associate'.$user->getId()] ??= $this->entityManager->getRepository(Associate::class)->findBy(['user' => $user]) : [];
    }

    public function isAssociateOf(?User $user, Organisation $organisation): bool
    {
        foreach ($this->associateships($user) as $associate) {
            if ($associate->getOrganisation()->getId() === $organisation->getId()) {
                return true;
            }
        }

        return false;
    }

    /** @return list<Lease> */
    public function tenancies(?User $user): array
    {
        return $user ? $this->memo['lease'.$user->getId()] ??= $this->leases->findForUser($user) : [];
    }

    public function isPartyOf(?User $user, Lease $lease): bool
    {
        return \in_array($lease->getId(), array_map(fn (Lease $l) => $l->getId(), $this->tenancies($user)), true);
    }

    /** @return list<Contractor> */
    public function contractorships(?User $user): array
    {
        return $user ? $this->memo['contractor'.$user->getId()] ??= $this->entityManager->getRepository(Contractor::class)->findBy(['user' => $user]) : [];
    }

    /**
     * The shares their account holds or held, oldest first; none while the
     * site does not run the `ownership` activity (its tables are not read).
     *
     * @return list<OwnershipShare>
     */
    public function coOwnerships(?User $user): array
    {
        if (!$user || !$this->activities?->has(Activity::OWNERSHIP)) {
            return [];
        }

        return $this->memo['coowner'.$user->getId()] ??= $this->entityManager->getRepository(OwnershipShare::class)->findBy(['user' => $user], ['startsOn' => 'ASC', 'id' => 'ASC']);
    }

    /** @return list<Ownership> the ownerships they hold a share of, each once */
    public function ownerships(?User $user): array
    {
        $ownerships = [];
        foreach ($this->coOwnerships($user) as $share) {
            $ownerships[$share->getOwnership()->getId()] = $share->getOwnership();
        }

        return array_values($ownerships);
    }

    public function isCoOwnerOf(?User $user, Ownership $ownership): bool
    {
        return \in_array($ownership->getId(), array_map(fn (Ownership $o) => $o->getId(), $this->ownerships($user)), true);
    }

    /** @return list<string> the holders they are (OwnershipShare::holder()) in an ownership */
    public function holdersIn(?User $user, Ownership $ownership): array
    {
        $holders = [];
        foreach ($this->coOwnerships($user) as $share) {
            if ($share->getOwnership()->getId() === $ownership->getId()) {
                $holders[$share->holder()] = true;
            }
        }

        return array_keys($holders);
    }

    /**
     * The owners their account is (a Mandator's user); none while the site
     * does not run the `management` activity (its tables are not read).
     *
     * @return list<Mandator>
     */
    public function mandators(?User $user): array
    {
        if (!$user || !$this->activities?->has(Activity::MANAGEMENT)) {
            return [];
        }

        return $this->memo['mandator'.$user->getId()] ??= $this->entityManager->getRepository(Mandator::class)->findBy(['user' => $user], ['name' => 'ASC', 'id' => 'ASC']);
    }

    public function isMandator(?User $user, Mandator $mandator): bool
    {
        return \in_array($mandator->getId(), array_map(fn (Mandator $m) => $m->getId(), $this->mandators($user)), true);
    }

    /** @return list<Mandate> the mandates of the owners they are, the latest started first */
    public function mandates(?User $user): array
    {
        $mandators = $this->mandators($user);
        if ([] === $mandators) {
            return [];
        }

        return $this->memo['mandate'.$user->getId()] ??= $this->entityManager->getRepository(Mandate::class)->findBy(['mandator' => $mandators], ['startsOn' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * The spaces the header and "Mon accès" offer, in this order.
     *
     * @return list<array{key: string, route: string}>
     */
    public function of(?User $user): array
    {
        $spaces = [];
        if ($this->associateships($user)) {
            $spaces[] = ['key' => 'associate', 'route' => 'estate_associate'];
        }
        if ($this->tenancies($user)) {
            $spaces[] = ['key' => 'tenant', 'route' => 'estate_tenant'];
        }
        if ($this->contractorships($user)) {
            $spaces[] = ['key' => 'contractor', 'route' => 'estate_contractor'];
        }
        if ($this->coOwnerships($user)) {
            $spaces[] = ['key' => 'coowner', 'route' => 'estate_coowner'];
        }
        if ($this->mandators($user)) {
            $spaces[] = ['key' => 'owner', 'route' => 'estate_owner'];
        }

        return $spaces;
    }
}
