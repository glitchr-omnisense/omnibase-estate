<?php

namespace Base\Estate\Service;

use Base\Entity\User;
use Base\Estate\Entity\Associate;
use Base\Estate\Entity\Contractor;
use Base\Estate\Entity\Lease;
use Base\Estate\Entity\Member;
use Base\Estate\Entity\Organisation;
use Base\Estate\Repository\LeaseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Who someone is to the organisations: their management (a Member, or any
 * ROLE_ADMIN), an associate, a tenant (a party of a lease), a contractor.
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

        return $spaces;
    }
}
