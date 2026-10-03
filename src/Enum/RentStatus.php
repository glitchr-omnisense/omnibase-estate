<?php

namespace Base\Estate\Enum;

enum RentStatus: string
{
    case CALLED = 'called';
    case PARTIAL = 'partial';
    case PAID = 'paid';
    case LATE = 'late';

    public function isOpen(): bool
    {
        return self::PAID !== $this;
    }
}
