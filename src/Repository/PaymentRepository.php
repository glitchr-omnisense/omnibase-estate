<?php

namespace Base\Estate\Repository;

use Base\Estate\Entity\Payment;
use Base\Estate\Enum\PaymentMethod;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Payment> */
class PaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payment::class);
    }

    /**
     * The money received since a day, per way of payment, in cents: one sum per method.
     *
     * @return array<string, int> by PaymentMethod value, the methods used only
     */
    public function totalsByMethod(\DateTimeImmutable $since): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.method AS method', 'SUM(p.amount) AS amount')
            ->where('p.paidOn >= :since')->setParameter('since', $since, 'date_immutable')
            ->groupBy('p.method')
            ->getQuery()->getScalarResult();
        $totals = [];
        foreach ($rows as $row) {
            $method = $row['method'] instanceof PaymentMethod ? $row['method']->value : (string) $row['method'];
            $totals[$method] = ($totals[$method] ?? 0) + (int) $row['amount'];
        }

        return $totals;
    }
}
