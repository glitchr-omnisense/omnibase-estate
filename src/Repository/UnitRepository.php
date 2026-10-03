<?php

namespace Base\Estate\Repository;

use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\Unit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Unit> */
class UnitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Unit::class);
    }

    /** @return list<Unit> what the public site lists: published, free ones first */
    public function findPublished(?string $kind = null, bool $availableOnly = false): array
    {
        $query = $this->createQueryBuilder('u')
            ->leftJoin('u.photos', 'p')->addSelect('p')
            ->where('u.published = true')
            ->orderBy('u.availableFrom', 'DESC')->addOrderBy('u.position', 'ASC')->addOrderBy('u.name', 'ASC');
        if ($kind) {
            $query->andWhere('u.kind = :kind')->setParameter('kind', $kind);
        }
        if ($availableOnly) {
            $query->andWhere('u.availableFrom IS NOT NULL');
        }

        return $query->getQuery()->getResult();
    }

    /** @return list<Unit> */
    public function findByOrganisation(Organisation $organisation): array
    {
        return $this->findBy(['organisation' => $organisation], ['position' => 'ASC', 'name' => 'ASC']);
    }
}
