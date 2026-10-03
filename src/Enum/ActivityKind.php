<?php

namespace Base\Estate\Enum;

enum ActivityKind: string
{
    case RENT_CALLED = 'rent_called';
    case RENT_PAID = 'rent_paid';
    case RENT_LATE = 'rent_late';
    case LEASE_STARTED = 'lease_started';
    case LEASE_ENDED = 'lease_ended';
    case WORK_REPORTED = 'work_reported';
    case WORK_ASSIGNED = 'work_assigned';
    case WORK_DONE = 'work_done';
    case DOCUMENT = 'document';
    case NOTICE = 'notice';
    case OTHER = 'other';

    public function icon(): string
    {
        return match ($this) {
            self::RENT_CALLED => 'fa-solid fa-file-invoice',
            self::RENT_PAID => 'fa-solid fa-circle-check',
            self::RENT_LATE => 'fa-solid fa-triangle-exclamation',
            self::LEASE_STARTED, self::LEASE_ENDED => 'fa-solid fa-key',
            self::WORK_REPORTED, self::WORK_ASSIGNED, self::WORK_DONE => 'fa-solid fa-screwdriver-wrench',
            self::DOCUMENT => 'fa-solid fa-file-lines',
            self::NOTICE => 'fa-solid fa-bullhorn',
            self::OTHER => 'fa-solid fa-circle',
        };
    }
}
