<?php

namespace Base\Estate\Ledger;

use Base\Estate\Entity\Ownership;
use Base\Ledger\Dimension\DimensionLabelerInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Names the ledger's "ownership" axis: the shared ownership's name
 * (Indivision Robertsau) for the id its postings carry (OwnershipBooks).
 * Only loaded when omnibase/ledger is installed.
 */
class OwnershipDimensionLabeler implements DimensionLabelerInterface
{
    public const AXIS = 'ownership';

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
        foreach ($this->entityManager->getRepository(Ownership::class)->findBy(['id' => $ids]) as $ownership) {
            $labels[(string) $ownership->getId()] = $ownership->getName();
        }

        return $labels;
    }
}
