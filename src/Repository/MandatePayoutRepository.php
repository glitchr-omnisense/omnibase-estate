<?php

namespace Base\Estate\Repository;

use Base\Estate\Entity\Mandate;
use Base\Estate\Entity\MandatePayout;
use Base\Estate\Enum\PayoutStatus;
use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Key;
use Base\Database\Repository\Finder\Parser;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<MandatePayout> */
class MandatePayoutRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, MandatePayout::class);
    }

    public function lastReference(string $prefix): ?string
    {
        $last = $this->maxOfReferenceBy([Group::of(Parser::SEPARATOR_AND, Criterion::of('reference', $prefix, Parser::OPTION_STARTING_WITH))]);

        return null === $last ? null : (string) $last;
    }

    /** @return list<MandatePayout> those not paid yet, the oldest first */
    public function findUnpaid(): array
    {
        return $this->findBy(
            [Group::of(Parser::SEPARATOR_AND, Criterion::of('status', PayoutStatus::PAID, Parser::OPTION_NOT_EQUAL))],
            ['issuedOn' => 'ASC', 'id' => 'ASC'],
        );
    }

    /** @return list<MandatePayout> a mandate's (every one's when null), the latest first */
    public function forMandate(?Mandate $mandate = null, int $limit = 200): array
    {
        return $this->findBy(null === $mandate ? [] : ['mandate' => Key::of($mandate)], ['issuedOn' => 'DESC', 'id' => 'DESC'], $limit);
    }
}
