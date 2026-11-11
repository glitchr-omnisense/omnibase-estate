<?php

namespace Base\Estate\Model;

/**
 * The caps the law set on an index's yearly change, for the revisions that
 * use a quarter of a given period - estate's rule, not the source's.
 *
 * The « bouclier loyer » (loi n° 2022-1158 du 16 août 2022, art. 12, extended
 * by loi n° 2023-568 du 7 juillet 2023): a residential rent revised on an IRL
 * of the quarters T3 2022 to T1 2024 moves by 3.5 % a year at most, 2.5 % in
 * the overseas departments (Guadeloupe, Martinique, Guyane, La Réunion,
 * Mayotte: postcodes 971 to 976). INSEE's published IRL of those quarters
 * already moves by 3.5 %; the overseas cap is the landlord's to apply.
 * Corsica's own cap (set by its assembly within 3.5 %) is not known here:
 * 3.5 % is applied there, to confirm.
 *
 * The ILC of small and medium-sized businesses (loi n° 2022-1158 du 16 août
 * 2022, art. 14, as amended by loi n° 2023-568 du 7 juillet 2023, art. 1;
 * version in force since 9 July 2023, read on Légifrance on 2026-11-11): the
 * yearly change of the ILC taken into account for the revision of the rent of
 * a small or medium-sized business (annex I to regulation (EU) n° 651/2014)
 * cannot exceed 3.5 % for the quarters from T2 2022 to T1 2024 - only when
 * the lease says its tenant is one (Lease::isSmallBusiness()). INSEE's ILC is
 * not capped: estate applies the cap. The cap is « définitivement acquis »: a
 * later revision cannot take back the part above 3.5 % over that period - how
 * a revision spanning several years across it is computed is not coded
 * (spansCap(): such a revision is left to the team).
 */
final class IndexCaps
{
    /** @var array<string, array{from: string, to: string, cap: float, overseas: float, smallBusiness: bool}> by index: the quarters (SDMX's 2022-Q3), the caps, whether only small businesses have it */
    private const CAPS = [
        'IRL' => ['from' => '2022-Q3', 'to' => '2024-Q1', 'cap' => 0.035, 'overseas' => 0.025, 'smallBusiness' => false],
        'ILC' => ['from' => '2022-Q2', 'to' => '2024-Q1', 'cap' => 0.035, 'overseas' => 0.035, 'smallBusiness' => true],
    ];

    /**
     * The highest yearly change allowed for a revision on $index of $quarter
     * (« T2 2023 ») for a home at $postcode, or a tenant who is a small business:
     * 0.035 for 3.5 %; null when no cap applies.
     */
    public static function limit(string $index, string $quarter, ?string $postcode = null, bool $smallBusiness = false): ?float
    {
        $caps = self::CAPS[strtoupper($index)] ?? null;
        $sdmx = self::sdmx($quarter);
        if (null === $caps || null === $sdmx || $sdmx < $caps['from'] || $sdmx > $caps['to'] || ($caps['smallBusiness'] && !$smallBusiness)) {
            return null;
        }

        return self::overseas($postcode) ? $caps['overseas'] : $caps['cap'];
    }

    /**
     * Whether a change on $index measured from $from to $quarter, longer than a year, crosses a
     * capped period that INSEE's values do not carry (the ILC's): the law's « définitivement
     * acquis » then decides the rent, which estate does not compute.
     */
    public static function spansCap(string $index, string $from, string $quarter, bool $smallBusiness = false): bool
    {
        $caps = self::CAPS[strtoupper($index)] ?? null;
        [$a, $b] = [self::sdmx($from), self::sdmx($quarter)];
        if (null === $caps || !$caps['smallBusiness'] || !$smallBusiness || null === $a || null === $b) {
            return false;
        }

        return !self::isOneYear($from, $quarter) && $a < $caps['to'] && $b > $caps['from'];
    }

    /** Whether $quarter is $from's quarter a year later (« T2 2025 », « T2 2026 »): a yearly change, the one a cap is set on. */
    public static function isOneYear(?string $from, string $quarter): bool
    {
        [$a, $b] = [self::sdmx((string) $from), self::sdmx($quarter)];

        return null !== $a && null !== $b && ((int) substr($a, 0, 4) + 1).substr($a, 4) === $b;
    }

    /** « T2 2023 » as « 2023-Q2 », which compares as text. */
    private static function sdmx(string $quarter): ?string
    {
        return 1 === preg_match('/^T([1-4])\s*(\d{4})$/', strtoupper(trim($quarter)), $m) ? $m[2].'-Q'.$m[1] : null;
    }

    private static function overseas(?string $postcode): bool
    {
        return null !== $postcode && 1 === preg_match('/^97[1-6]/', trim($postcode));
    }
}
