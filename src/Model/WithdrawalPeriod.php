<?php

namespace Base\Estate\Model;

/**
 * The buyer's withdrawal period after a compromis or promesse de vente
 * (délai de rétractation, art. L271-1 of the Code de la construction et de
 * l'habitation, the loi SRU's, 10 days since the loi Macron): it runs from
 * the day after the deed was notified to the buyer (the first presentation
 * of the registered letter, or the hand delivery against a receipt) and
 * ends on the tenth day at midnight; a period that would end on a
 * Saturday, a Sunday or a public holiday runs until the next working day
 * (art. 642 of the Code de procédure civile).
 *
 * The French public holidays; Alsace-Moselle adds Good Friday and
 * St Stephen's Day (26 December) on demand.
 */
final class WithdrawalPeriod
{
    public const DAYS = 10;

    /** Its last day: the buyer may withdraw until that day's midnight. */
    public static function end(\DateTimeInterface $notifiedOn, bool $alsaceMoselle = false): \DateTimeImmutable
    {
        $end = \DateTimeImmutable::createFromInterface($notifiedOn)->setTime(0, 0)->modify('+'.self::DAYS.' days');
        while (!self::isWorkingDay($end, $alsaceMoselle)) {
            $end = $end->modify('+1 day');
        }

        return $end;
    }

    /** Neither a Saturday, a Sunday nor a public holiday. */
    public static function isWorkingDay(\DateTimeInterface $day, bool $alsaceMoselle = false): bool
    {
        return (int) $day->format('N') < 6 && !self::isHoliday($day, $alsaceMoselle);
    }

    public static function isHoliday(\DateTimeInterface $day, bool $alsaceMoselle = false): bool
    {
        $year = (int) $day->format('Y');
        $easter = self::easter($year);
        $holidays = ['01-01', '05-01', '05-08', '07-14', '08-15', '11-01', '11-11', '12-25',
            $easter->modify('+1 day')->format('m-d'), $easter->modify('+39 days')->format('m-d'), $easter->modify('+50 days')->format('m-d')];
        if ($alsaceMoselle) {
            array_push($holidays, $easter->modify('-2 days')->format('m-d'), '12-26');
        }

        return \in_array($day->format('m-d'), $holidays, true);
    }

    /** Easter Sunday of a Gregorian year (the anonymous algorithm, Meeus/Jones/Butcher): no calendar extension needed. */
    public static function easter(int $year): \DateTimeImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $h = (19 * $a + $b - intdiv($b, 4) - intdiv($b - intdiv($b + 8, 25) + 1, 3) + 15) % 30;
        $l = (32 + 2 * ($b % 4) + 2 * intdiv($c, 4) - $h - $c % 4) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = ($h + $l - 7 * $m + 114) % 31 + 1;

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }
}
