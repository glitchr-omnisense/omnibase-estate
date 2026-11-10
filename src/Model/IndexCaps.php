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
 */
final class IndexCaps
{
    /** @var array<string, array{from: string, to: string, cap: float, overseas: float}> by index: the quarters (SDMX's 2022-Q3) and the caps */
    private const CAPS = [
        'IRL' => ['from' => '2022-Q3', 'to' => '2024-Q1', 'cap' => 0.035, 'overseas' => 0.025],
    ];

    /**
     * The highest yearly change allowed for a revision on $index of $quarter
     * (« T2 2023 ») for a home at $postcode: 0.035 for 3.5 %; null when no cap
     * applies.
     */
    public static function limit(string $index, string $quarter, ?string $postcode = null): ?float
    {
        $caps = self::CAPS[strtoupper($index)] ?? null;
        $sdmx = self::sdmx($quarter);
        if (null === $caps || null === $sdmx || $sdmx < $caps['from'] || $sdmx > $caps['to']) {
            return null;
        }

        return self::overseas($postcode) ? $caps['overseas'] : $caps['cap'];
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
