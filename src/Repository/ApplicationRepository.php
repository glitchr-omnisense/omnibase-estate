<?php

namespace Base\Estate\Repository;

use Base\Estate\Entity\Application;
use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\Unit;
use Base\Estate\Enum\ApplicationStatus;
use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Parser;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<Application> */
class ApplicationRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, Application::class);
    }

    /** @return list<Application> a home's applications still open, the oldest first */
    public function openFor(Unit $unit): array
    {
        return $this->findBy(['unit' => $unit, 'status' => [ApplicationStatus::NEW, ApplicationStatus::SHORTLISTED, ApplicationStatus::VISIT]], ['createdAt' => 'ASC']);
    }

    /** New applications not looked at yet, for the dashboard. */
    public function countNew(Organisation $organisation): int
    {
        return $this->count(['unit.organisation' => $organisation, 'status' => ApplicationStatus::NEW]);
    }

    /**
     * countNew() for several organisations: one count each (the finder language has no GROUP BY).
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
        $counts = [];
        foreach ($organisations as $organisation) {
            if (0 < $count = $this->countNew($organisation)) {
                $counts[(int) $organisation->getId()] = $count;
            }
        }

        return $counts;
    }

    /** @return list<Application> decided more than $months ago: to delete */
    public function expired(int $months = 3): array
    {
        return $this->findBy([Group::of(Parser::SEPARATOR_AND,
            Criterion::of('decidedAt', null, Parser::OPTION_NOT_NULL),
            Criterion::of('decidedAt', new \DateTimeImmutable('-'.$months.' months'), Parser::OPTION_LOWER),
        )]);
    }
}
