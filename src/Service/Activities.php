<?php

namespace Base\Estate\Service;

use Base\Estate\Enum\Activity;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The activities this site runs (estate.activities, [assets] by default):
 * what its back office shows and, in the next steps, which screens and
 * postings exist. In Twig: estate_activity('ownership'), estate_activities().
 */
class Activities
{
    /** @var list<Activity> */
    private readonly array $activities;

    /** @param list<string|Activity> $activities */
    public function __construct(#[Autowire('%estate.activities%')] array $activities = ['assets'])
    {
        $cases = [];
        foreach ($activities as $activity) {
            $case = $activity instanceof Activity ? $activity : Activity::from((string) $activity);
            $cases[$case->value] = $case;
        }
        // In the enum's order, whatever the configuration's.
        $this->activities = array_values(array_filter(Activity::cases(), fn (Activity $case) => isset($cases[$case->value])));
    }

    public function has(Activity|string $activity): bool
    {
        $activity = $activity instanceof Activity ? $activity : Activity::from($activity);

        return \in_array($activity, $this->activities, true);
    }

    /** Whether one of $activities at least is run: a screen shared by several shows when any is. */
    public function any(Activity|string ...$activities): bool
    {
        foreach ($activities as $activity) {
            if ($this->has($activity)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<Activity> */
    public function all(): array
    {
        return $this->activities;
    }
}
