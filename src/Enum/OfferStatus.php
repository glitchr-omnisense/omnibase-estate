<?php

namespace Base\Estate\Enum;

/**
 * Where a buyer's offer stands: made (waiting for the seller's answer),
 * countered (the seller asks another price), accepted (the sale begins:
 * Entity\Sale), refused, withdrawn by the buyer.
 */
enum OfferStatus: string
{
    case MADE = 'made';
    case COUNTERED = 'countered';
    case ACCEPTED = 'accepted';
    case REFUSED = 'refused';
    case WITHDRAWN = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::MADE => 'Faite',
            self::COUNTERED => 'Contre-proposition',
            self::ACCEPTED => 'Acceptée',
            self::REFUSED => 'Refusée',
            self::WITHDRAWN => 'Retirée',
        };
    }

    /** Still waiting for an answer: the seller may accept, counter or refuse it. */
    public function isOpen(): bool
    {
        return self::MADE === $this || self::COUNTERED === $this;
    }
}
