<?php

namespace Base\Estate\Enum;

/** How a sale mandate's fee is stated: a percentage of the sale price, or a fixed amount. */
enum FeeBasis: string
{
    case PERCENT = 'percent';
    case FIXED = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::PERCENT => 'Pourcentage du prix',
            self::FIXED => 'Forfait',
        };
    }
}
