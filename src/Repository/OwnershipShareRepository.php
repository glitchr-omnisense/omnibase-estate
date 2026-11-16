<?php

namespace Base\Estate\Repository;

use Base\Database\Repository\Finder\Key;
use Base\Entity\User;
use Base\Estate\Entity\OwnershipShare;
use Base\Database\Repository\Finders;
use Base\Database\Repository\ServiceEntityRepository;

/** @extends ServiceEntityRepository<OwnershipShare> */
class OwnershipShareRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, OwnershipShare::class);
    }

    /** @return list<OwnershipShare> the shares an account holds or held, by ownership name then start (OwnershipRepository::sharesOf()) */
    public function heldBy(User $user): array
    {
        return $this->findBy(['user' => Key::of($user)], ['ownership.name' => 'ASC', 'startsOn' => 'ASC']);
    }
}
