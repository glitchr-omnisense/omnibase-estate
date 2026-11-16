<?php

namespace Base\Estate\Repository;

use Base\Estate\Entity\Document;
use Base\Estate\Entity\Lease;
use Base\Estate\Entity\WorkOrder;
use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Key;
use Base\Database\Repository\Finder\Parser;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<Document> */
class DocumentRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, Document::class);
    }

    /**
     * What a lease's tenants see: the lease's documents and its home's, those shared with them.
     *
     * @return list<Document>
     */
    public function forTenants(Lease $lease): array
    {
        return $this->findBy([
            Group::of(Parser::SEPARATOR_OR,
                Criterion::of('lease', Key::of($lease)),
                Group::of(Parser::SEPARATOR_AND,
                    Criterion::of('unit', Key::of($lease->getUnit())),
                    Criterion::of('lease', Key::of(null)),
                    Criterion::of('workOrder', Key::of(null)),
                ),
            ),
            'tenantVisible' => true,
        ], ['createdAt' => 'DESC']);
    }

    /** @return list<Document> */
    public function forWorkOrder(WorkOrder $order): array
    {
        return $this->findBy(['workOrder' => $order], ['createdAt' => 'ASC']);
    }
}
