<?php

namespace Base\Estate\Repository;

use Base\Estate\Entity\Activity;
use Base\Estate\Entity\Organisation;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<Activity> */
class ActivityRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, Activity::class);
    }

    /** @return list<Activity> the latest first */
    public function latest(Organisation $organisation, int $limit = 12): array
    {
        return $this->findBy(['organisation' => $organisation], ['happenedAt' => 'DESC', 'id' => 'DESC'], $limit);
    }
}
