<?php

namespace Base\Estate\Repository;

use Base\Estate\Entity\Activity;
use Base\Estate\Entity\Organisation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Activity> */
class ActivityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Activity::class);
    }

    /** @return list<Activity> the latest first */
    public function latest(Organisation $organisation, int $limit = 12): array
    {
        return $this->findBy(['organisation' => $organisation], ['happenedAt' => 'DESC', 'id' => 'DESC'], $limit);
    }
}
