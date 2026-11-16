<?php

namespace Base\Estate\Repository;

use Base\Estate\Entity\StoryEntry;
use Base\Estate\Entity\WorkOrder;
use Base\Estate\Enum\StoryState;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<StoryEntry> */
class StoryEntryRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, StoryEntry::class);
    }

    /** @return list<StoryEntry> the works under way the team published, the latest started first */
    public function findInProgress(): array
    {
        return $this->findBy(['published' => true, 'state' => StoryState::IN_PROGRESS], ['startedOn' => 'DESC', 'happenedOn' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * @return list<StoryEntry> the works done the team published (entries telling work orders), the latest first
     *
     * The finder language has no IS NOT EMPTY on a collection: the published done entries are read
     * in order and those telling no work order left out here, the first $limit kept.
     */
    public function findDoneWorks(int $limit = 24): array
    {
        $works = [];
        foreach ($this->findBy(['published' => true, 'state' => StoryState::DONE], ['happenedOn' => 'DESC', 'id' => 'DESC']) as $entry) {
            if ($entry->getWorkOrders()->isEmpty()) {
                continue;
            }
            $works[] = $entry;
            if (\count($works) >= $limit) {
                break;
            }
        }

        return $works;
    }

    /** The entry telling a work order, if one does. */
    public function findOneByWorkOrder(WorkOrder $order): ?StoryEntry
    {
        return $this->findOneBy(['workOrders' => $order]);
    }
}
