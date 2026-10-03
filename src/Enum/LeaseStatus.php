<?php

namespace Base\Estate\Enum;

enum LeaseStatus: string
{
    case ACTIVE = 'active';
    /** The tenant gave notice: the lease runs until its end date. */
    case NOTICE = 'notice';
    case ENDED = 'ended';
}
