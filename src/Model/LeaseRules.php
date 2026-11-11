<?php

namespace Base\Estate\Model;

use Base\Estate\Entity\Lease;
use Base\Estate\Enum\LeaseKind;

/**
 * What the law asks the team to check on a lease, said on its screen and never enforced:
 * estate does not know everything the rule depends on. Each says its source.
 */
final class LeaseRules
{
    /**
     * Code de commerce, art. L145-40, al. 2 (loi n° 2026-403 du 26 mai 2026, art. 62; version in
     * force since 28 May 2026, read on Légifrance on 2026-11-11): the sums a tenant of premises
     * « destiné à l'exercice d'une activité de commerce de détail ou de gros ou de prestations de
     * services à caractère commercial ou artisanal » (art. L145-32-1) pays as a guarantee cannot
     * exceed a quarter's rent; for the leases made or renewed from the law's promulgation, on 26 May
     * 2026 (its art. 62, II, B). Whether the premises are of that kind, and whether the quarter's rent
     * is counted with or without VAT, the text does not let estate decide: a warning, not a rule.
     */
    public const DEPOSIT_CAP_FROM = '2026-05-26';

    /** @return list<string> the warnings of $lease, in French, each with its source */
    public static function warnings(Lease $lease): array
    {
        $warnings = [];
        if (LeaseKind::COMMERCIAL === $lease->getKind() && $lease->getStartOn() >= new \DateTimeImmutable(self::DEPOSIT_CAP_FROM) && $lease->getDeposit() > 3 * $lease->getRent()) {
            $warnings[] = sprintf(
                'Dépôt de garantie de %s pour un loyer trimestriel de %s : si le local est destiné au commerce de détail ou de gros ou à des prestations de services commerciales ou artisanales (art. L145-32-1), les sommes versées en garantie ne peuvent excéder un trimestre de loyer (code de commerce, art. L145-40, al. 2, version du 28 mai 2026, pour les baux conclus ou renouvelés depuis le 26 mai 2026).',
                self::euros($lease->getDeposit()),
                self::euros(3 * $lease->getRent()),
            );
        }

        return $warnings;
    }

    private static function euros(int $cents): string
    {
        return number_format($cents / 100, 2, ',', "\u{202F}").' €';
    }
}
