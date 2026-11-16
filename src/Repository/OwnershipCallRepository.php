<?php

namespace Base\Estate\Repository;

use Base\Estate\Entity\Ownership;
use Base\Estate\Entity\OwnershipCall;
use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Key;
use Base\Database\Repository\Finder\Parser;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<OwnershipCall> */
class OwnershipCallRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, OwnershipCall::class);
    }

    public function lastReference(string $prefix): ?string
    {
        $last = $this->maxOfReferenceBy([Group::of(Parser::SEPARATOR_AND, Criterion::of('reference', $prefix, Parser::OPTION_STARTING_WITH))]);

        return null === $last ? null : (string) $last;
    }

    /** @return list<OwnershipCall> an ownership's calls (every one's when null), the latest first */
    public function forOwnership(?Ownership $ownership = null): array
    {
        return $this->findBy(null === $ownership ? [] : ['ownership' => Key::of($ownership)], ['issuedOn' => 'DESC', 'id' => 'DESC']);
    }

    /** @return list<OwnershipCall> the calls not paid yet, the oldest due first */
    public function findOpen(): array
    {
        return array_values(array_filter(
            $this->findBy([], ['dueOn' => 'ASC']),
            fn (OwnershipCall $call) => !$call->isPaid(),
        ));
    }
}
