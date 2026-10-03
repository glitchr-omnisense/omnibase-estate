<?php

namespace Base\Estate\Service;

use Base\Estate\Entity\Activity;
use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\Unit;
use Base\Estate\Enum\ActivityKind;
use Doctrine\ORM\EntityManagerInterface;

/** Writes the associates' feed: one line per thing that happened. Persisted, not flushed. */
class Activities
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function log(Organisation $organisation, ActivityKind $kind, string $message, ?Unit $unit = null, ?int $amount = null, ?\DateTimeImmutable $at = null): Activity
    {
        $activity = new Activity($organisation, $kind, $message, $unit, $amount, $at);
        $this->entityManager->persist($activity);

        return $activity;
    }
}
