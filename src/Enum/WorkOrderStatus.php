<?php

namespace Base\Estate\Enum;

enum WorkOrderStatus: string
{
    case REPORTED = 'reported';
    case ASSIGNED = 'assigned';
    case SCHEDULED = 'scheduled';
    case DONE = 'done';
    case CLOSED = 'closed';
    case CANCELLED = 'cancelled';

    public function isOpen(): bool
    {
        return !\in_array($this, [self::CLOSED, self::CANCELLED], true);
    }
}
