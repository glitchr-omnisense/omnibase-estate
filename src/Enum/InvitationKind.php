<?php

namespace Base\Estate\Enum;

/** What accepting an invitation opens: the target it was made for gets the new account. */
enum InvitationKind: string
{
    case MEMBER = 'member';
    case ASSOCIATE = 'associate';
    case TENANT = 'tenant';
    case CONTRACTOR = 'contractor';
}
