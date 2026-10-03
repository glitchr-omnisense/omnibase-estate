<?php

namespace Base\Estate\Enum;

/** Who stands surety for an applicant: Action Logement's free Visale, a person, a company or bank, or nobody. */
enum Guarantee: string
{
    case VISALE = 'visale';
    case PERSON = 'person';
    case COMPANY = 'company';
    case NONE = 'none';
}
