<?php

namespace Base\Estate\Repository;

use Base\Estate\Entity\Mandator;
use Base\Estate\Entity\SaleMandate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SaleMandate> */
class SaleMandateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SaleMandate::class);
    }

    /** @return list<SaleMandate> the mandates ending between today and $until, the soonest first */
    public function ending(\DateTimeImmutable $today, \DateTimeImmutable $until): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.endsOn >= :today')->andWhere('m.endsOn <= :until')
            ->setParameter('today', $today->setTime(0, 0))->setParameter('until', $until->setTime(0, 0))
            ->orderBy('m.endsOn', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return list<SaleMandate> the mandates running on $day, the latest started first */
    public function inForce(\DateTimeImmutable $day): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.startsOn <= :day')->andWhere('m.endsOn >= :day')->setParameter('day', $day->setTime(0, 0))
            ->orderBy('m.startsOn', 'DESC')->addOrderBy('m.id', 'DESC')
            ->getQuery()->getResult();
    }

    /**
     * @param list<Mandator> $sellers
     *
     * @return list<SaleMandate> the mandates they sign as sellers, the latest started first
     */
    public function forSellers(array $sellers): array
    {
        if ([] === $sellers) {
            return [];
        }

        return $this->createQueryBuilder('m')->join('m.sellers', 's')
            ->where('s IN (:sellers)')->setParameter('sellers', $sellers)
            ->orderBy('m.startsOn', 'DESC')->addOrderBy('m.id', 'DESC')
            ->getQuery()->getResult();
    }

    public function lastNumber(string $prefix): ?string
    {
        return $this->createQueryBuilder('m')
            ->select('MAX(m.number)')
            ->where('m.number LIKE :prefix')->setParameter('prefix', $prefix.'%')
            ->getQuery()->getSingleScalarResult();
    }
}
