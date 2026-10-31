<?php

namespace Base\Estate\Enum;

/** How a buyer pays: in cash (their own funds), or with a loan (its amount on the buyer). */
enum Financing: string
{
    case CASH = 'cash';
    case LOAN = 'loan';

    public function label(): string
    {
        return match ($this) {
            self::CASH => 'Comptant',
            self::LOAN => 'Avec un prêt',
        };
    }
}
