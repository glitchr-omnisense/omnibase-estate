<?php

namespace Base\Estate\Enum;

/**
 * What a room of a home is. Whether it counts toward the living area by
 * default follows the French rules (loi Carrez, surface habitable): a
 * balcony, a terrace, a cellar, a garage do not; the team ticks otherwise
 * per room (a loggia closed in, a converted attic).
 */
enum RoomKind: string
{
    case LIVING = 'living';
    case DINING = 'dining';
    case KITCHEN = 'kitchen';
    case BEDROOM = 'bedroom';
    case OFFICE = 'office';
    case BATHROOM = 'bathroom';
    case SHOWER = 'shower';
    case WC = 'wc';
    case ENTRANCE = 'entrance';
    case CORRIDOR = 'corridor';
    case STORAGE = 'storage';
    case LAUNDRY = 'laundry';
    case BALCONY = 'balcony';
    case TERRACE = 'terrace';
    case CELLAR = 'cellar';
    case GARAGE = 'garage';
    case OTHER = 'other';

    /** Counted in the living area unless the team says otherwise. */
    public function countsByDefault(): bool
    {
        return !\in_array($this, [self::BALCONY, self::TERRACE, self::CELLAR, self::GARAGE], true);
    }
}
