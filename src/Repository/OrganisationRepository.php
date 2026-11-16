<?php

namespace Base\Estate\Repository;

use Base\Estate\Entity\Organisation;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<Organisation> */
class OrganisationRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, Organisation::class);
    }
}
