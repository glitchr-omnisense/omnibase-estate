<?php

namespace Base\Estate\Enum;

use Base\Estate\Model\Period;

/**
 * How often a management mandate's owner gets their statement (compte
 * rendu de gérance) and their payout: every month (the default), every
 * quarter, every year. Its periods are Model\Period's keys.
 */
enum StatementFrequency: string
{
    case MONTHLY = 'monthly';
    case QUARTERLY = 'quarterly';
    case YEARLY = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::MONTHLY => 'Mensuel',
            self::QUARTERLY => 'Trimestriel',
            self::YEARLY => 'Annuel',
        };
    }

    /** The period a day falls in: "2026-10", "2026-Q4", "2026". */
    public function periodOf(\DateTimeInterface $day): string
    {
        return match ($this) {
            self::MONTHLY => $day->format('Y-m'),
            self::QUARTERLY => $day->format('Y').'-Q'.intdiv((int) $day->format('n') + 2, 3),
            self::YEARLY => $day->format('Y'),
        };
    }

    /** The last period over on $today: the month, quarter or year before the one it is in. */
    public function lastClosed(\DateTimeImmutable $today): string
    {
        [$from] = Period::parse($this->periodOf($today));

        return $this->periodOf($from->modify('-1 day'));
    }

    /**
     * The last $count periods over on $today, the latest first.
     *
     * @return list<string>
     */
    public function recent(\DateTimeImmutable $today, int $count = 6): array
    {
        $keys = [];
        $key = $this->lastClosed($today);
        for ($i = 0; $i < $count; ++$i) {
            $keys[] = $key;
            [$from] = Period::parse($key);
            $key = $this->periodOf($from->modify('-1 day'));
        }

        return $keys;
    }
}
