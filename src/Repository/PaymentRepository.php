<?php

namespace Base\Estate\Repository;

use Base\Estate\Entity\Payment;
use Base\Estate\Enum\PaymentMethod;
use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Key;
use Base\Database\Repository\Finder\Parser;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<Payment> */
class PaymentRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, Payment::class);
    }

    /**
     * The money received since a day, per way of payment, in cents: one sum per method (a sum
     * per PaymentMethod case, the finder language having no GROUP BY; null when none was used).
     *
     * @return array<string, int> by PaymentMethod value, the methods used only
     */
    public function totalsByMethod(\DateTimeImmutable $since): array
    {
        $totals = [];
        foreach (PaymentMethod::cases() as $method) {
            $amount = $this->sumOfAmountBy([
                'method' => $method,
                Group::of(Parser::SEPARATOR_AND, Criterion::of('paidOn', $since, Parser::OPTION_GREATER_EQUAL)),
            ]);
            if (null !== $amount) {
                $totals[$method->value] = (int) $amount;
            }
        }

        return $totals;
    }

    /** @return list<Payment> those whose reference starts with $prefix ("bank:<line>:", BankUnsettlement) */
    public function withReferenceStartingWith(string $prefix): array
    {
        return $this->findBy([Group::of(Parser::SEPARATOR_AND, Criterion::of('reference', $prefix, Parser::OPTION_STARTING_WITH))]);
    }

    /**
     * @param list<\Base\Estate\Entity\Unit> $units
     *
     * @return list<Payment> the payments of these homes' rent calls, the oldest first (Ownerships)
     */
    public function forUnits(array $units): array
    {
        return $this->findBy(['rentCall.lease.unit' => array_values($units)], ['paidOn' => 'ASC', 'id' => 'ASC']);
    }
}
