<?php

namespace Base\Estate\Repository;

use Base\Entity\User;
use Base\Estate\Entity\Lease;
use Base\Estate\Entity\Organisation;
use Base\Estate\Enum\LeaseStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Lease> */
class LeaseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Lease::class);
    }

    /** @return list<Lease> the leases running during a month, to be called */
    public function findRunningIn(\DateTimeImmutable $month, ?Organisation $organisation = null): array
    {
        $first = $month->modify('first day of this month')->setTime(0, 0);
        $last = $month->modify('last day of this month')->setTime(0, 0);
        $query = $this->createQueryBuilder('l')
            ->join('l.unit', 'u')
            ->where('l.status != :ended')->setParameter('ended', LeaseStatus::ENDED)
            ->andWhere('l.startOn <= :last')->setParameter('last', $last, 'date_immutable')
            ->andWhere('l.endOn IS NULL OR l.endOn >= :first')->setParameter('first', $first, 'date_immutable');
        if ($organisation) {
            $query->andWhere('u.organisation = :organisation')->setParameter('organisation', $organisation);
        }

        return $query->getQuery()->getResult();
    }

    /** @return list<Lease> the leases someone is a party of, the running ones first */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('l')
            ->join('l.parties', 'p')
            ->where('p.user = :user')->setParameter('user', $user)
            ->orderBy('l.status', 'ASC')->addOrderBy('l.startOn', 'DESC')
            ->getQuery()->getResult();
    }
}
