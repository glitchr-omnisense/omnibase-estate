<?php

namespace Base\Estate\Repository;

use Base\Entity\User;
use Base\Estate\Entity\Mandate;
use Base\Estate\Entity\Mandator;
use Base\Estate\Entity\Unit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Mandate> */
class MandateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Mandate::class);
    }

    /** The mandate a home is managed under on $on, if any (the latest started when two overlap). */
    public function findForUnit(Unit $unit, \DateTimeInterface $on): ?Mandate
    {
        $day = \DateTimeImmutable::createFromInterface($on)->setTime(0, 0);

        return $this->createQueryBuilder('m')->join('m.units', 'u')
            ->where('u = :unit')->setParameter('unit', $unit)
            ->andWhere('m.startsOn <= :day')->andWhere('m.endsOn IS NULL OR m.endsOn >= :day')->setParameter('day', $day)
            ->orderBy('m.startsOn', 'DESC')->addOrderBy('m.id', 'DESC')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /** @return list<Mandate> the mandates running on one day at least of the period, by owner */
    public function inForce(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('m')->join('m.mandator', 'o')->addSelect('o')
            ->where('m.startsOn <= :to')->andWhere('m.endsOn IS NULL OR m.endsOn >= :from')
            ->setParameter('from', $from->setTime(0, 0))->setParameter('to', $to->setTime(0, 0))
            ->orderBy('o.name', 'ASC')->addOrderBy('m.startsOn', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return list<Mandate> the mandates ending between today and $until, the soonest first */
    public function ending(\DateTimeImmutable $today, \DateTimeImmutable $until): array
    {
        return $this->createQueryBuilder('m')->join('m.mandator', 'o')->addSelect('o')
            ->where('m.endsOn >= :today')->andWhere('m.endsOn <= :until')
            ->setParameter('today', $today->setTime(0, 0))->setParameter('until', $until->setTime(0, 0))
            ->orderBy('m.endsOn', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * @param list<Mandator> $mandators
     *
     * @return list<Mandate> their mandates, the latest started first
     */
    public function forMandators(array $mandators): array
    {
        if ([] === $mandators) {
            return [];
        }

        return $this->createQueryBuilder('m')
            ->where('m.mandator IN (:mandators)')->setParameter('mandators', $mandators)
            ->orderBy('m.startsOn', 'DESC')->addOrderBy('m.id', 'DESC')
            ->getQuery()->getResult();
    }

    /** @return list<Mandator> the owners an account is */
    public function mandatorsOf(User $user): array
    {
        return $this->getEntityManager()->getRepository(Mandator::class)->findBy(['user' => $user], ['name' => 'ASC']);
    }

    public function lastNumber(string $prefix): ?string
    {
        return $this->createQueryBuilder('m')
            ->select('MAX(m.number)')
            ->where('m.number LIKE :prefix')->setParameter('prefix', $prefix.'%')
            ->getQuery()->getSingleScalarResult();
    }
}
