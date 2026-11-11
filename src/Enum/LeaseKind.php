<?php

namespace Base\Estate\Enum;

/**
 * What a lease is: a home's (loi du 6 juillet 1989), a commercial lease
 * (Code de commerce, art. L145-1 and after) or a professional one. It decides
 * the index a rent follows and the rules of its revision (Service\RentRevisions)
 * and which statements a guarantor's deed carries (Signature\SuretySignatures).
 */
enum LeaseKind: string
{
    case RESIDENTIAL = 'residential';
    case COMMERCIAL = 'commercial';
    case PROFESSIONAL = 'professional';

    public function label(): string
    {
        return match ($this) {
            self::RESIDENTIAL => 'Bail d\'habitation',
            self::COMMERCIAL => 'Bail commercial',
            self::PROFESSIONAL => 'Bail professionnel',
        };
    }

    /** The indices a rent of this kind may follow: the IRL for a home, the ILC or the ILAT otherwise. */
    public function indices(): array
    {
        return self::RESIDENTIAL === $this ? ['IRL'] : ['ILC', 'ILAT'];
    }

    /** The revision rules a lease of this kind may carry. */
    public function rules(): array
    {
        return match ($this) {
            self::RESIDENTIAL => [RevisionRule::YEARLY, RevisionRule::NONE],
            self::COMMERCIAL => [RevisionRule::INDEXATION, RevisionRule::TRIENNIAL, RevisionRule::NONE],
            self::PROFESSIONAL => [RevisionRule::INDEXATION, RevisionRule::NONE],
        };
    }
}
