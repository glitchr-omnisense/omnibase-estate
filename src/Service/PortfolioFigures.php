<?php

namespace Base\Estate\Service;

use Base\Estate\Entity\Organisation;
use Base\Estate\Repository\RentCallRepository;
use Base\Estate\Repository\UnitRepository;

/**
 * Several organisations in numbers, read together: what the back office's dashboard shows for
 * a property manager with many companies. Each figure is one query for them all, grouped or
 * summed in the database (Figures reads one organisation at a time, entity by entity); the
 * organisations are the ones the card shows, and only theirs are read.
 */
class PortfolioFigures
{
    /** A lease ends soon when its end date falls within this. */
    public const ENDING_WITHIN = '+3 months';

    public function __construct(
        private readonly UnitRepository $units,
        private readonly RentCallRepository $calls,
    ) {
    }

    /**
     * The homes (no parking space) and where each stands on a day: vacant, its lease ending
     * (notice given, or an end date within three months), or let; and the rent roll of its
     * current lease (rent and charges). The organisations by name, then each one's homes in
     * their order.
     *
     * @param list<Organisation> $organisations
     *
     * @return list<array{unit: int, name: string, state: 'vacant'|'ending'|'let', monthly: int}>
     */
    public function homes(array $organisations, \DateTimeImmutable $today): array
    {
        return array_map(static fn (array $home) => [
            'unit' => $home['unit'],
            'name' => $home['name'],
            'state' => null === $home['lease'] ? 'vacant' : (self::isEnding((bool) $home['notice'], $home['endOn'], $today) ? 'ending' : 'let'),
            'monthly' => $home['monthly'] ?? 0,
        ], $this->units->homes($organisations, $today));
    }

    /** Whether a running lease is ending: notice given, or its end date within ENDING_WITHIN. */
    public static function isEnding(bool $notice, ?\DateTimeImmutable $endOn, \DateTimeImmutable $today): bool
    {
        return $notice || (null !== $endOn && $endOn <= $today->modify(self::ENDING_WITHIN));
    }

    /**
     * The rents called and received month by month, the $count months to this one, oldest
     * first: Figures::months() for these organisations together.
     *
     * @param list<Organisation> $organisations
     *
     * @return list<array{month: \DateTimeImmutable, called: int, received: int, count: int}>
     */
    public function months(array $organisations, int $count, \DateTimeImmutable $today): array
    {
        $first = $today->modify('first day of this month')->setTime(0, 0);
        $totals = $this->calls->monthTotals($organisations, $first->modify(sprintf('-%d months', $count - 1)), $first);
        $months = [];
        for ($i = $count - 1; $i >= 0; --$i) {
            $month = $first->modify("-$i months");
            $months[] = ['month' => $month] + ($totals[$month->format('Y-m')] ?? ['called' => 0, 'received' => 0, 'count' => 0]);
        }

        return $months;
    }
}
