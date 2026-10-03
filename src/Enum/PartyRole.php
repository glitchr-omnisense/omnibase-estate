<?php

namespace Base\Estate\Enum;

/** Who someone is on a lease. */
enum PartyRole: string
{
    case TENANT = 'tenant';
    case COTENANT = 'cotenant';
    case GUARANTOR = 'guarantor';
}
