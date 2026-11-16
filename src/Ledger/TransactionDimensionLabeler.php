<?php

namespace Base\Estate\Ledger;

use Base\Estate\Entity\SaleMandate;
use Base\Ledger\Dimension\DimensionLabelerInterface;
use Base\Database\Repository\Repositories;

/**
 * Names the ledger's "transaction" axis: the sale mandate's number and
 * property (VT-2026-0001 — Maison 5 pièces, Obernai) for the id the sales'
 * postings carry (TransactionBooks). Only loaded when omnibase/ledger is
 * installed.
 */
class TransactionDimensionLabeler implements DimensionLabelerInterface
{
    public const AXIS = 'transaction';

    public function __construct(private readonly Repositories $repositories)
    {
    }

    public function supports(string $axis): bool
    {
        return self::AXIS === $axis;
    }

    public function labels(string $axis, array $values): array
    {
        $ids = array_values(array_filter(array_map('intval', $values)));
        if ([] === $ids) {
            return [];
        }
        $labels = [];
        foreach ($this->repositories->of(SaleMandate::class)->findBy(['id' => $ids]) as $mandate) {
            $labels[(string) $mandate->getId()] = (string) $mandate;
        }

        return $labels;
    }
}
