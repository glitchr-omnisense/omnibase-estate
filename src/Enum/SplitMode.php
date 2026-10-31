<?php

namespace Base\Estate\Enum;

/**
 * How a shared invoice is divided among the co-owners: by their shares in
 * force on its date (the default), or set by hand - amounts, or
 * percentages.
 */
enum SplitMode: string
{
    case SHARES = 'shares';
    case AMOUNTS = 'amounts';
    case PERCENTAGES = 'percentages';

    public function label(): string
    {
        return match ($this) {
            self::SHARES => 'Selon les quotes-parts',
            self::AMOUNTS => 'Montants saisis',
            self::PERCENTAGES => 'Pourcentages saisis',
        };
    }
}
