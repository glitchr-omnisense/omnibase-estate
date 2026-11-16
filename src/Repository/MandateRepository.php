<?php

namespace Base\Estate\Repository;

use Base\Database\Repository\Repositories;
use Base\Entity\User;
use Base\Estate\Entity\Mandate;
use Base\Estate\Entity\Mandator;
use Base\Estate\Entity\Unit;
use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Key;
use Base\Database\Repository\Finder\Parser;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<Mandate> */
class MandateRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders, private readonly Repositories $repositories)
    {
        parent::__construct($finders, Mandate::class);
    }

    /** The mandate a home is managed under on $on, if any (the latest started when two overlap). */
    public function findForUnit(Unit $unit, \DateTimeInterface $on): ?Mandate
    {
        $day = \DateTimeImmutable::createFromInterface($on)->setTime(0, 0);

        return $this->findOneBy([
            'units' => $unit,
            Group::of(Parser::SEPARATOR_AND, Criterion::of('startsOn', $day, Parser::OPTION_LOWER_EQUAL)),
            Group::of(Parser::SEPARATOR_OR,
                Criterion::of('endsOn', null, Parser::OPTION_NULL),
                Criterion::of('endsOn', $day, Parser::OPTION_GREATER_EQUAL),
            ),
        ], ['startsOn' => 'DESC', 'id' => 'DESC']);
    }

    /** @return list<Mandate> the mandates running on one day at least of the period, by owner */
    public function inForce(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->findBy([
            Group::of(Parser::SEPARATOR_AND, Criterion::of('startsOn', $to->setTime(0, 0), Parser::OPTION_LOWER_EQUAL)),
            Group::of(Parser::SEPARATOR_OR,
                Criterion::of('endsOn', null, Parser::OPTION_NULL),
                Criterion::of('endsOn', $from->setTime(0, 0), Parser::OPTION_GREATER_EQUAL),
            ),
        ], ['mandator.name' => 'ASC', 'startsOn' => 'ASC']);
    }

    /** @return list<Mandate> the mandates ending between today and $until, the soonest first */
    public function ending(\DateTimeImmutable $today, \DateTimeImmutable $until): array
    {
        return $this->findBy([Group::of(Parser::SEPARATOR_AND,
            Criterion::of('endsOn', $today->setTime(0, 0), Parser::OPTION_GREATER_EQUAL),
            Criterion::of('endsOn', $until->setTime(0, 0), Parser::OPTION_LOWER_EQUAL),
        )], ['endsOn' => 'ASC']);
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

        return $this->findBy(['mandator' => Key::of($mandators)], ['startsOn' => 'DESC', 'id' => 'DESC']);
    }

    /** @return list<Mandator> the owners an account is */
    public function mandatorsOf(User $user): array
    {
        return $this->repositories->of(Mandator::class)->findBy(['user' => $user], ['name' => 'ASC']);
    }

    public function lastNumber(string $prefix): ?string
    {
        $last = $this->maxOfNumberBy([Group::of(Parser::SEPARATOR_AND, Criterion::of('number', $prefix, Parser::OPTION_STARTING_WITH))]);

        return null === $last ? null : (string) $last;
    }
}
