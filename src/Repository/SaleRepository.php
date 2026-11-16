<?php

namespace Base\Estate\Repository;

use Base\Estate\Entity\Sale;
use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Parser;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<Sale> */
class SaleRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, Sale::class);
    }

    /** The last fee invoice number given under a prefix (FH-2026-). */
    public function lastInvoiceNumber(string $prefix): ?string
    {
        $last = $this->maxOfInvoiceNumberBy([Group::of(Parser::SEPARATOR_AND, Criterion::of('invoiceNumber', $prefix, Parser::OPTION_STARTING_WITH))]);

        return null === $last ? null : (string) $last;
    }
}
