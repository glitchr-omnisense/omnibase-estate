<?php

namespace Base\Estate\Enum;

enum Urgency: string
{
    case LOW = 'low';
    case NORMAL = 'normal';
    case URGENT = 'urgent';
}
