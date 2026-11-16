<?php

namespace Base\Estate\Repository;

use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Key;
use Base\Database\Repository\Finder\Parser;
use Base\Estate\Entity\Lease;
use Base\Estate\Entity\Notice;
use Base\Database\Repository\Finders;
use Base\Database\Repository\ServiceEntityRepository;

/** @extends ServiceEntityRepository<Notice> */
class NoticeRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, Notice::class);
    }

    /** @return list<Notice> the latest notices sent to a lease's building (or to the whole organisation), the tenant's page */
    public function latestSentFor(Lease $lease, int $limit = 5): array
    {
        $building = $lease->getUnit()->getBuilding();

        return $this->findBy([
            'organisation' => Key::of($lease->getOrganisation()),
            Group::of(Parser::SEPARATOR_OR,
                Criterion::of('building', Key::of(null)),
                ...(null === $building ? [] : [Criterion::of('building', Key::of($building))]),
            ),
            Group::of(Parser::SEPARATOR_AND, Criterion::of('sentAt', null, Parser::OPTION_NOT_NULL)),
        ], ['sentAt' => 'DESC'], $limit);
    }
}
