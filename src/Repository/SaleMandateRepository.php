<?php

namespace Base\Estate\Repository;

use Base\Estate\Entity\Mandator;
use Base\Estate\Entity\SaleMandate;
use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Parser;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<SaleMandate> */
class SaleMandateRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, SaleMandate::class);
    }

    /** @return list<SaleMandate> the mandates ending between today and $until, the soonest first */
    public function ending(\DateTimeImmutable $today, \DateTimeImmutable $until): array
    {
        return $this->findBy([Group::of(Parser::SEPARATOR_AND,
            Criterion::of('endsOn', $today->setTime(0, 0), Parser::OPTION_GREATER_EQUAL),
            Criterion::of('endsOn', $until->setTime(0, 0), Parser::OPTION_LOWER_EQUAL),
        )], ['endsOn' => 'ASC']);
    }

    /** @return list<SaleMandate> the mandates running on $day, the latest started first */
    public function inForce(\DateTimeImmutable $day): array
    {
        $day = $day->setTime(0, 0);

        return $this->findBy([Group::of(Parser::SEPARATOR_AND,
            Criterion::of('startsOn', $day, Parser::OPTION_LOWER_EQUAL),
            Criterion::of('endsOn', $day, Parser::OPTION_GREATER_EQUAL),
        )], ['startsOn' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * @param list<Mandator> $sellers
     *
     * @return list<SaleMandate> the mandates they sign as sellers, the latest started first
     */
    public function forSellers(array $sellers): array
    {
        if ([] === $sellers) {
            return [];
        }

        return $this->findBy(['sellers' => $sellers], ['startsOn' => 'DESC', 'id' => 'DESC']);
    }

    public function lastNumber(string $prefix): ?string
    {
        $last = $this->maxOfNumberBy([Group::of(Parser::SEPARATOR_AND, Criterion::of('number', $prefix, Parser::OPTION_STARTING_WITH))]);

        return null === $last ? null : (string) $last;
    }
}
