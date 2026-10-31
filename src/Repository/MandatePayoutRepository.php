<?php

namespace Base\Estate\Repository;

use Base\Estate\Entity\Mandate;
use Base\Estate\Entity\MandatePayout;
use Base\Estate\Enum\PayoutStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MandatePayout> */
class MandatePayoutRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MandatePayout::class);
    }

    public function lastReference(string $prefix): ?string
    {
        return $this->createQueryBuilder('p')
            ->select('MAX(p.reference)')
            ->where('p.reference LIKE :prefix')->setParameter('prefix', $prefix.'%')
            ->getQuery()->getSingleScalarResult();
    }

    /** @return list<MandatePayout> those not paid yet, the oldest first */
    public function findUnpaid(): array
    {
        return $this->createQueryBuilder('p')->join('p.mandate', 'm')->addSelect('m')
            ->where('p.status != :paid')->setParameter('paid', PayoutStatus::PAID)
            ->orderBy('p.issuedOn', 'ASC')->addOrderBy('p.id', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return list<MandatePayout> a mandate's (every one's when null), the latest first */
    public function forMandate(?Mandate $mandate = null, int $limit = 200): array
    {
        $query = $this->createQueryBuilder('p')->join('p.mandate', 'm')->addSelect('m')
            ->orderBy('p.issuedOn', 'DESC')->addOrderBy('p.id', 'DESC')->setMaxResults($limit);
        if (null !== $mandate) {
            $query->where('p.mandate = :mandate')->setParameter('mandate', $mandate);
        }

        return $query->getQuery()->getResult();
    }
}
