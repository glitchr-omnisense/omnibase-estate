<?php

namespace Base\Estate\Enum;

/**
 * How a lease's rent is revised (Service\RentRevisions):
 *
 * - YEARLY: a home's yearly revision on the IRL, on the anniversary, a year not claimed
 *   within the year lost (loi du 6 juillet 1989, art. 17-1);
 * - INDEXATION: the lease's own indexation clause (clause d'échelle mobile), yearly on the
 *   anniversary on the index it names (ILC or ILAT) - the contract's rule, which estate reads
 *   as « the same quarter a year later »; a commercial lease whose clause moved the rent by
 *   more than a quarter may be revised at a party's request (Code de commerce, art. L145-39,
 *   version of 20 June 2014), which estate only tells;
 * - TRIENNIAL: a commercial lease's revision at a party's request, three years at least after
 *   the tenant's entry or the last price, effective on the day it is asked, the change capped
 *   by the index's variation since the last price unless a material change of the local
 *   commercial factors is proved (Code de commerce, art. L145-38, version of 20 June 2014);
 * - NONE: no revision clause.
 */
enum RevisionRule: string
{
    case YEARLY = 'yearly';
    case INDEXATION = 'indexation';
    case TRIENNIAL = 'triennial';
    case NONE = 'none';

    public function label(): string
    {
        return match ($this) {
            self::YEARLY => 'Révision annuelle (IRL, art. 17-1 de la loi du 6 juillet 1989)',
            self::INDEXATION => 'Clause d\'indexation annuelle',
            self::TRIENNIAL => 'Révision triennale (art. L145-38 du code de commerce)',
            self::NONE => 'Pas de révision',
        };
    }
}
