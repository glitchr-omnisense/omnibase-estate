<?php

namespace Base\Estate\Enum;

enum UnitKind: string
{
    case HOUSE = 'house';
    case APARTMENT = 'apartment';
    case STUDIO = 'studio';
    case PARKING = 'parking';
    case COMMERCIAL = 'commercial';
}
