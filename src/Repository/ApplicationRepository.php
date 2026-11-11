<?php

namespace Base\Estate\Repository;

use Base\Estate\Entity\Application;
use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\Unit;
use Base\Estate\Enum\ApplicationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Application> */
class ApplicationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Application::class);
    }

    /** @return list<Application> a home's applications still open, the oldest first */
    public function openFor(Unit $unit): array
    {
        return $this->findBy(['unit' => $unit, 'status' => [ApplicationStatus::NEW, ApplicationStatus::SHORTLISTED, ApplicationStatus::VISIT]], ['createdAt' => 'ASC']);
    }

    /** New applications not looked at yet, for the dashboard. */
    public function countNew(Organisation $organisation): int
    {
        return (int) $this->createQueryBuilder('a')->select('COUNT(a.id)')->join('a.unit', 'u')
            ->where('u.organisation = :organisation')->setParameter('organisation', $organisation)
            ->andWhere('a.status = :new')->setParameter('new', ApplicationStatus::NEW)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * countNew() for several organisations in one query.
     *
     * @param list<Organisation> $organisations
     *
     * @return array<int, int> by organisation id, the organisations with new applications only
     */
    public function countNewByOrganisation(array $organisations): array
    {
        if ([] === $organisations) {
            return [];
        }
        $rows = $this->createQueryBuilder('a')->join('a.unit', 'u')
            ->select('IDENTITY(u.organisation) AS organisation', 'COUNT(a.id) AS applications')
            ->where('u.organisation IN (:organisations)')->setParameter('organisations', $organisations)
            ->andWhere('a.status = :new')->setParameter('new', ApplicationStatus::NEW)
            ->groupBy('u.organisation')
            ->getQuery()->getScalarResult();

        return array_column(array_map(static fn (array $r) => [(int) $r['organisation'], (int) $r['applications']], $rows), 1, 0);
    }

    /** @return list<Application> decided more than $months ago: to delete */
    public function expired(int $months = 3): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.decidedAt IS NOT NULL AND a.decidedAt < :limit')->setParameter('limit', new \DateTimeImmutable('-'.$months.' months'))
            ->getQuery()->getResult();
    }
}
