<?php

namespace Base\Estate\Enum;

/** Where an application stands: received, shortlisted, invited to visit, accepted (a lease follows) or declined. */
enum ApplicationStatus: string
{
    case NEW = 'new';
    case SHORTLISTED = 'shortlisted';
    case VISIT = 'visit';
    case ACCEPTED = 'accepted';
    case DECLINED = 'declined';

    public function isOpen(): bool
    {
        return \in_array($this, [self::NEW, self::SHORTLISTED, self::VISIT], true);
    }
}
