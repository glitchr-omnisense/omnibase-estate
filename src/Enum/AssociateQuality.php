<?php

namespace Base\Estate\Enum;

enum AssociateQuality: string
{
    /** Runs the company (gérant·e). */
    case MANAGER = 'manager';
    case ASSOCIATE = 'associate';
}
