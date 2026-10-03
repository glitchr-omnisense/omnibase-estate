<?php

namespace Base\Estate\Repository;

use Base\Estate\Entity\Document;
use Base\Estate\Entity\Lease;
use Base\Estate\Entity\WorkOrder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Document> */
class DocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Document::class);
    }

    /**
     * What a lease's tenants see: the lease's documents and its home's, those shared with them.
     *
     * @return list<Document>
     */
    public function forTenants(Lease $lease): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.lease = :lease OR (d.unit = :unit AND d.lease IS NULL AND d.workOrder IS NULL)')
            ->andWhere('d.tenantVisible = true')
            ->setParameter('lease', $lease)->setParameter('unit', $lease->getUnit())
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()->getResult();
    }

    /** @return list<Document> */
    public function forWorkOrder(WorkOrder $order): array
    {
        return $this->findBy(['workOrder' => $order], ['createdAt' => 'ASC']);
    }
}
