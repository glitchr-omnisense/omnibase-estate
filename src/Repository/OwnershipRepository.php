<?php

namespace Base\Estate\Repository;

use Base\Entity\User;
use Base\Estate\Entity\Ownership;
use Base\Estate\Entity\OwnershipShare;
use Base\Estate\Entity\Unit;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<Ownership> */
class OwnershipRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders, private readonly OwnershipShareRepository $shares)
    {
        parent::__construct($finders, Ownership::class);
    }

    /** The shared ownership a home belongs to, if any. */
    public function findForUnit(Unit $unit): ?Ownership
    {
        return $this->findOneBy(['units' => $unit], ['id' => 'ASC']);
    }

    /** @return list<OwnershipShare> the shares an account holds or held, their ownerships with them */
    public function sharesOf(User $user): array
    {
        return $this->shares->heldBy($user);
    }
}
