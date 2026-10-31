<?php

namespace Base\Estate\Repository;

use Base\Entity\User;
use Base\Estate\Entity\Ownership;
use Base\Estate\Entity\OwnershipShare;
use Base\Estate\Entity\Unit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Ownership> */
class OwnershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ownership::class);
    }

    /** The shared ownership a home belongs to, if any. */
    public function findForUnit(Unit $unit): ?Ownership
    {
        return $this->createQueryBuilder('o')->join('o.units', 'u')
            ->where('u = :unit')->setParameter('unit', $unit)
            ->orderBy('o.id', 'ASC')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /** @return list<OwnershipShare> the shares an account holds or held, their ownerships with them */
    public function sharesOf(User $user): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('s', 'o')->from(OwnershipShare::class, 's')->join('s.ownership', 'o')
            ->where('s.user = :user')->setParameter('user', $user)
            ->orderBy('o.name', 'ASC')->addOrderBy('s.startsOn', 'ASC')
            ->getQuery()->getResult();
    }
}
