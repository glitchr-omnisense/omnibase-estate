<?php

namespace Base\Estate\Repository;

use Base\Estate\Entity\StoryEntry;
use Base\Estate\Entity\WorkOrder;
use Base\Estate\Enum\StoryState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<StoryEntry> */
class StoryEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StoryEntry::class);
    }

    /** @return list<StoryEntry> the works under way the team published, the latest started first */
    public function findInProgress(): array
    {
        return $this->createQueryBuilder('e')->join('e.unit', 'u')->addSelect('u')
            ->where('e.published = true')->andWhere('e.state = :state')->setParameter('state', StoryState::IN_PROGRESS)
            ->orderBy('e.startedOn', 'DESC')->addOrderBy('e.happenedOn', 'DESC')->addOrderBy('e.id', 'DESC')
            ->getQuery()->getResult();
    }

    /** @return list<StoryEntry> the works done the team published (entries telling work orders), the latest first */
    public function findDoneWorks(int $limit = 24): array
    {
        return $this->createQueryBuilder('e')->join('e.unit', 'u')->addSelect('u')
            ->where('e.published = true')->andWhere('e.state = :state')->setParameter('state', StoryState::DONE)
            ->andWhere('e.workOrders IS NOT EMPTY')
            ->orderBy('e.happenedOn', 'DESC')->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /** The entry telling a work order, if one does. */
    public function findOneByWorkOrder(WorkOrder $order): ?StoryEntry
    {
        return $this->createQueryBuilder('e')->join('e.workOrders', 'w')
            ->where('w = :order')->setParameter('order', $order)
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }
}
