<?php

namespace Base\Estate\Ledger;

use Base\Estate\Entity\Unit;
use Base\Ledger\Dimension\DimensionLabelerInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Names the ledger's "unit" axis: the unit's name (Appartement Krutenau)
 * for the id estate posts its entries with (Service\Books). Only loaded
 * when omnibase/ledger is installed.
 */
class UnitDimensionLabeler implements DimensionLabelerInterface
{
    public const AXIS = 'unit';

    public function __construct(private readonly EntityManagerInterface $entityManager)
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
        foreach ($this->entityManager->getRepository(Unit::class)->findBy(['id' => $ids]) as $unit) {
            $labels[(string) $unit->getId()] = $unit->getName();
        }

        return $labels;
    }
}
