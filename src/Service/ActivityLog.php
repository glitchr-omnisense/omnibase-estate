<?php

namespace Base\Estate\Service;

use Base\Estate\Entity\Activity;
use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\Unit;
use Base\Estate\Enum\ActivityKind;
use Base\Contract\UnitOfWork;

/** Writes the associates' feed (estate_activity): one line per thing that happened. Persisted, not flushed. Named ActivityLog since Activities is the switch of the activities a site runs. */
class ActivityLog
{
    public function __construct(private readonly UnitOfWork $unitOfWork)
    {
    }

    public function log(Organisation $organisation, ActivityKind $kind, string $message, ?Unit $unit = null, ?int $amount = null, ?\DateTimeImmutable $at = null): Activity
    {
        $activity = new Activity($organisation, $kind, $message, $unit, $amount, $at);
        $this->unitOfWork->persist($activity);

        return $activity;
    }
}
