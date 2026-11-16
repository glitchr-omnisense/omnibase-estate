<?php

namespace Base\Estate\Ledger;

use Base\Estate\Entity\Mandator;
use Base\Ledger\Dimension\DimensionLabelerInterface;
use Base\Database\Repository\Repositories;

/**
 * Names the ledger's "owner" axis: the owner's name (Hélène Roth) for the
 * id the management's postings carry (ManagementBooks). Only loaded when
 * omnibase/ledger is installed.
 */
class OwnerDimensionLabeler implements DimensionLabelerInterface
{
    public const AXIS = 'owner';

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
        foreach ($this->repositories->of(Mandator::class)->findBy(['id' => $ids]) as $mandator) {
            $labels[(string) $mandator->getId()] = $mandator->getName();
        }

        return $labels;
    }
}
