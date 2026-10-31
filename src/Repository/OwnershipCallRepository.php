<?php

namespace Base\Estate\Repository;

use Base\Estate\Entity\Ownership;
use Base\Estate\Entity\OwnershipCall;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<OwnershipCall> */
class OwnershipCallRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OwnershipCall::class);
    }

    public function lastReference(string $prefix): ?string
    {
        return $this->createQueryBuilder('c')
            ->select('MAX(c.reference)')
            ->where('c.reference LIKE :prefix')->setParameter('prefix', $prefix.'%')
            ->getQuery()->getSingleScalarResult();
    }

    /** @return list<OwnershipCall> an ownership's calls (every one's when null), the latest first */
    public function forOwnership(?Ownership $ownership = null): array
    {
        $query = $this->createQueryBuilder('c')->join('c.share', 's')->addSelect('s')
            ->orderBy('c.issuedOn', 'DESC')->addOrderBy('c.id', 'DESC');
        if (null !== $ownership) {
            $query->where('c.ownership = :ownership')->setParameter('ownership', $ownership);
        }

        return $query->getQuery()->getResult();
    }

    /** @return list<OwnershipCall> the calls not paid yet, the oldest due first */
    public function findOpen(): array
    {
        return array_values(array_filter(
            $this->createQueryBuilder('c')->orderBy('c.dueOn', 'ASC')->getQuery()->getResult(),
            fn (OwnershipCall $call) => !$call->isPaid(),
        ));
    }
}
