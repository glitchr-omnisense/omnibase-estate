<?php

namespace Base\Estate\Model;

/**
 * A statement's period, as a key: a month ("2026-10"), a quarter
 * ("2026-Q3") or a year ("2026").
 */
final class OwnershipPeriod
{
    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} its first and last days */
    public static function parse(string $key): array
    {
        $key = strtoupper(trim($key));
        if (preg_match('/^(\d{4})-(\d{2})$/', $key, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
            $from = new \DateTimeImmutable(sprintf('%s-%s-01', $m[1], $m[2]));

            return [$from, $from->modify('last day of this month')];
        }
        if (preg_match('/^(\d{4})-[QT]([1-4])$/', $key, $m)) {
            $from = new \DateTimeImmutable(sprintf('%s-%02d-01', $m[1], 3 * (int) $m[2] - 2));

            return [$from, $from->modify('+2 months')->modify('last day of this month')];
        }
        if (preg_match('/^\d{4}$/', $key)) {
            return [new \DateTimeImmutable($key.'-01-01'), new \DateTimeImmutable($key.'-12-31')];
        }
        throw new \InvalidArgumentException(sprintf('"%s" is not a period: 2026-10, 2026-Q3 or 2026.', $key));
    }

    public static function key(\DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        return match (self::kind($from, $to)) {
            'month' => $from->format('Y-m'),
            'quarter' => $from->format('Y').'-Q'.intdiv((int) $from->format('n') + 2, 3),
            'year' => $from->format('Y'),
            default => $from->format('Ymd').'-'.$to->format('Ymd'),
        };
    }

    /** month, quarter, year, or range. */
    public static function kind(\DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        $first = '01' === $from->format('d');
        $last = $to->format('Y-m-d') === $to->modify('last day of this month')->format('Y-m-d');
        $months = ((int) $to->format('Y') - (int) $from->format('Y')) * 12 + (int) $to->format('n') - (int) $from->format('n') + 1;

        return match (true) {
            !$first || !$last => 'range',
            1 === $months => 'month',
            3 === $months && 1 === (int) $from->format('n') % 3 => 'quarter',
            12 === $months && '01' === $from->format('m') => 'year',
            default => 'range',
        };
    }

    /** In French, for the PDF and the e-mails: « octobre 2026 », « 3e trimestre 2026 », « année 2026 ». */
    public static function label(\DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        $months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

        return match (self::kind($from, $to)) {
            'month' => $months[(int) $from->format('n') - 1].' '.$from->format('Y'),
            'quarter' => (1 === ($q = intdiv((int) $from->format('n') + 2, 3)) ? '1er' : $q.'e').' trimestre '.$from->format('Y'),
            'year' => 'année '.$from->format('Y'),
            default => 'du '.$from->format('d/m/Y').' au '.$to->format('d/m/Y'),
        };
    }

    /**
     * The periods a statement is offered for around $today: the last months, quarters and years.
     *
     * @return list<string>
     */
    public static function recent(\DateTimeImmutable $today, int $months = 3, int $quarters = 2, int $years = 2): array
    {
        $keys = [];
        $month = $today->modify('first day of this month');
        for ($i = 0; $i < $months; ++$i) {
            $keys[] = $month->modify("-$i months")->format('Y-m');
        }
        $quarter = $month->modify(sprintf('-%d months', ((int) $month->format('n') - 1) % 3));
        for ($i = 0; $i < $quarters; ++$i) {
            $start = $quarter->modify(sprintf('-%d months', 3 * $i));
            $keys[] = self::key($start, $start->modify('+2 months')->modify('last day of this month'));
        }
        for ($i = 0; $i < $years; ++$i) {
            $keys[] = (string) ((int) $today->format('Y') - $i);
        }

        return $keys;
    }
}
