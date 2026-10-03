<?php

namespace Base\Estate\Enum;

/** An applicant's work situation, as a landlord weighs a file. */
enum Occupation: string
{
    case PERMANENT = 'permanent';
    case FIXED_TERM = 'fixed_term';
    case CIVIL_SERVANT = 'civil_servant';
    case SELF_EMPLOYED = 'self_employed';
    case STUDENT = 'student';
    case RETIRED = 'retired';
    case JOB_SEEKER = 'job_seeker';
    case OTHER = 'other';
}
