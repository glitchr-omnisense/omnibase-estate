<?php

namespace Base\Estate\Model;

/**
 * The public holidays of a place in France, by its postcode: the nation's eleven, and those of its
 * department - Alsace-Moselle's Good Friday and St Stephen's Day (26 December, the local law of the
 * Bas-Rhin, Haut-Rhin and Moselle), the overseas departments' and collectivities' day of the
 * abolition of slavery (loi n° 83-550, décret n° 83-1003). Without a postcode, or one not French,
 * the nation's only.
 */
final class PublicHolidays
{
    /** Each department's own, as 'm-d' or 'good_friday'; Saint-Barthélemy and Saint-Martin, though their postcodes are Guadeloupe's, have theirs. */
    private const LOCAL = [
        '57' => ['good_friday', '12-26'],
        '67' => ['good_friday', '12-26'],
        '68' => ['good_friday', '12-26'],
        '971' => ['05-27'],
        '97133' => ['10-09'],
        '97150' => ['05-28'],
        '972' => ['05-22'],
        '973' => ['06-10'],
        '974' => ['12-20'],
        '976' => ['04-27'],
    ];

    /** Where local holidays apply: a department's number ("67", "974"), Saint-Barthélemy's or Saint-Martin's postcode, or null. */
    public static function place(?string $postcode): ?string
    {
        $postcode = preg_replace('/\s+/', '', (string) $postcode);
        if (!preg_match('/^\d{5}$/', $postcode)) {
            return null;
        }
        foreach ([$postcode, substr($postcode, 0, 3), substr($postcode, 0, 2)] as $key) {
            if (isset(self::LOCAL[$key])) {
                return $key;
            }
        }

        return null;
    }

    /** Whether a place has holidays of its own: Alsace-Moselle, an overseas department. */
    public static function isLocal(?string $postcode): bool
    {
        return null !== self::place($postcode);
    }

    /** @return list<string> a year's holidays at a place, as 'm-d' */
    public static function of(int $year, ?string $postcode = null): array
    {
        $easter = self::easter($year);
        $days = ['01-01', '05-01', '05-08', '07-14', '08-15', '11-01', '11-11', '12-25',
            $easter->modify('+1 day')->format('m-d'), $easter->modify('+39 days')->format('m-d'), $easter->modify('+50 days')->format('m-d')];
        foreach (self::LOCAL[self::place($postcode)] ?? [] as $day) {
            $days[] = 'good_friday' === $day ? $easter->modify('-2 days')->format('m-d') : $day;
        }

        return $days;
    }

    public static function isHoliday(\DateTimeInterface $day, ?string $postcode = null): bool
    {
        return \in_array($day->format('m-d'), self::of((int) $day->format('Y'), $postcode), true);
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
