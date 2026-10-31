<?php

namespace Base\Estate\Enum;

/**
 * Where an owner's payout stands: issued (the amount due taken off their
 * account, to transfer), exported (in a SEPA file given to the bank),
 * paid (the bank line seen and reconciled, or marked paid by hand).
 */
enum PayoutStatus: string
{
    case ISSUED = 'issued';
    case EXPORTED = 'exported';
    case PAID = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::ISSUED => 'À virer',
            self::EXPORTED => 'Dans un fichier SEPA',
            self::PAID => 'Payé',
        };
    }
}
