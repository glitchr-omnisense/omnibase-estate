<?php

namespace Base\Estate\Repository;

use Base\Entity\User;
use Base\Estate\Entity\Lease;
use Base\Estate\Entity\Organisation;
use Base\Estate\Enum\LeaseStatus;
use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Parser;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<Lease> */
class LeaseRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, Lease::class);
    }

    /** @return list<Lease> the leases running during a month, to be called */
    public function findRunningIn(\DateTimeImmutable $month, ?Organisation $organisation = null): array
    {
        $first = $month->modify('first day of this month')->setTime(0, 0);
        $last = $month->modify('last day of this month')->setTime(0, 0);
        $criteria = [
            Group::of(Parser::SEPARATOR_AND,
                Criterion::of('status', LeaseStatus::ENDED, Parser::OPTION_NOT_EQUAL),
                Criterion::of('startOn', $last, Parser::OPTION_LOWER_EQUAL),
            ),
            Group::of(Parser::SEPARATOR_OR,
                Criterion::of('endOn', null, Parser::OPTION_NULL),
                Criterion::of('endOn', $first, Parser::OPTION_GREATER_EQUAL),
            ),
        ];
        if ($organisation) {
            $criteria['unit.organisation'] = $organisation;
        }

        return $this->findBy($criteria);
    }

    /** @return list<Lease> the active leases, their home and parties with them */
    public function findActive(): array
    {
        return $this->findBy(['status' => LeaseStatus::ACTIVE], ['id' => 'ASC']);
    }

    /**
     * The active leases of these organisations without a valid insurance certificate on a day
     * (none, or run out before it), their parties with them.
     *
     * @param list<Organisation> $organisations
     *
     * @return list<Lease>
     */
    public function findUninsured(array $organisations, \DateTimeImmutable $on): array
    {
        if ([] === $organisations) {
            return [];
        }

        return $this->findBy([
            'status' => LeaseStatus::ACTIVE,
            'unit.organisation' => $organisations,
            Group::of(Parser::SEPARATOR_OR,
                Criterion::of('insuranceUntil', null, Parser::OPTION_NULL),
                Criterion::of('insuranceUntil', $on, Parser::OPTION_LOWER),
            ),
        ], ['id' => 'ASC']);
    }

    /** @return list<string> the references given under a prefix (Applications' next lease reference) */
    public function referencesStartingWith(string $prefix): array
    {
        return array_map(
            static fn (Lease $lease) => $lease->getReference(),
            $this->findBy([Group::of(Parser::SEPARATOR_AND, Criterion::of('reference', $prefix, Parser::OPTION_STARTING_WITH))]),
        );
    }

    /** @return list<Lease> the leases someone is a party of, the running ones first */
    public function findForUser(User $user): array
    {
        return $this->findBy(['parties.user' => $user], ['status' => 'ASC', 'startOn' => 'DESC']);
    }
}
