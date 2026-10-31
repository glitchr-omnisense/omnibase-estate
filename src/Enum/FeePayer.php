<?php

namespace Base\Estate\Enum;

/**
 * Who pays a sale's fee, as the mandate says (« honoraires à la charge du
 * vendeur / de l'acquéreur »): the agency invoices them at the acte
 * authentique.
 */
enum FeePayer: string
{
    case SELLER = 'seller';
    case BUYER = 'buyer';

    public function label(): string
    {
        return match ($this) {
            self::SELLER => 'Le vendeur',
            self::BUYER => 'L\'acquéreur',
        };
    }
}
