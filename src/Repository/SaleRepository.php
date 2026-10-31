<?php

namespace Base\Estate\Repository;

use Base\Estate\Entity\Sale;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Sale> */
class SaleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Sale::class);
    }

    /** The last fee invoice number given under a prefix (FH-2026-). */
    public function lastInvoiceNumber(string $prefix): ?string
    {
        return $this->createQueryBuilder('s')
            ->select('MAX(s.invoiceNumber)')
            ->where('s.invoiceNumber LIKE :prefix')->setParameter('prefix', $prefix.'%')
            ->getQuery()->getSingleScalarResult();
    }
}
