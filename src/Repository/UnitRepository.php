<?php

namespace Base\Estate\Repository;

use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\Unit;
use Base\Estate\Enum\LeaseStatus;
use Base\Estate\Enum\UnitKind;
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

    /**
     * The homes of these organisations - every unit but the parking spaces - each with the lease
     * running on a day, as Unit::getCurrentLease() picks it (not ended, started, not past its
     * end; the latest started first): scalars, the organisations by name, then each one's homes
     * in their order. lease, notice, endOn and monthly are null for a home not let.
     *
     * @param list<Organisation> $organisations
     *
     * @return list<array{unit: int, name: string, lease: ?int, notice: ?bool, endOn: ?\DateTimeImmutable, monthly: ?int}>
     */
    public function homes(array $organisations, \DateTimeImmutable $on): array
    {
        if ([] === $organisations) {
            return [];
        }
        $rows = $this->createQueryBuilder('u')->join('u.organisation', 'o')
            ->leftJoin('u.leases', 'l', 'WITH', 'l.status != :ended AND l.startOn <= :on AND (l.endOn IS NULL OR l.endOn >= :on)')
            ->select('u.id AS unit', 'u.name AS name', 'l.id AS lease', 'l.endOn AS endOn', 'l.rent + l.charges AS monthly', 'CASE WHEN l.status = :notice THEN 1 ELSE 0 END AS notice')
            ->where('u.organisation IN (:organisations)')->setParameter('organisations', $organisations)
            ->andWhere('u.kind != :parking')->setParameter('parking', UnitKind::PARKING)
            ->setParameter('ended', LeaseStatus::ENDED)->setParameter('notice', LeaseStatus::NOTICE)->setParameter('on', $on, 'date_immutable')
            ->orderBy('o.name', 'ASC')->addOrderBy('o.id', 'ASC')->addOrderBy('u.position', 'ASC')->addOrderBy('u.name', 'ASC')->addOrderBy('u.id', 'ASC')
            ->addOrderBy('l.startOn', 'DESC')->addOrderBy('l.id', 'ASC')
            ->getQuery()->getArrayResult();
        $homes = [];
        foreach ($rows as $row) {
            if (isset($homes[$row['unit']])) {
                continue; // a second lease running: the first one counts, as getCurrentLease() returns it
            }
            $let = null !== $row['lease'];
            $endOn = $row['endOn'];
            $homes[$row['unit']] = [
                'unit' => (int) $row['unit'],
                'name' => (string) $row['name'],
                'lease' => $let ? (int) $row['lease'] : null,
                'notice' => $let ? 1 === (int) $row['notice'] : null,
                'endOn' => $let && null !== $endOn ? ($endOn instanceof \DateTimeImmutable ? $endOn : new \DateTimeImmutable($endOn)) : null,
                'monthly' => $let ? (int) $row['monthly'] : null,
            ];
        }

        return array_values($homes);
    }

    /**
     * These units, their leases and the leases' parties with them, in the order of $ids.
     *
     * @param list<int> $ids
     *
     * @return list<Unit>
     */
    public function findWithLeases(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        $units = [];
        foreach ($this->createQueryBuilder('u')->leftJoin('u.leases', 'l')->leftJoin('l.parties', 'p')->addSelect('l', 'p')
            ->where('u.id IN (:ids)')->setParameter('ids', $ids)
            // The order the collections load in by themselves: Unit::$leases' latest started first, the parties as entered.
            ->orderBy('l.startOn', 'DESC')->addOrderBy('l.id', 'ASC')->addOrderBy('p.id', 'ASC')
            ->getQuery()->getResult() as $unit) {
            $units[$unit->getId()] = $unit;
        }

        return array_values(array_filter(array_map(static fn (int $id) => $units[$id] ?? null, $ids)));
    }

    /** @return list<Unit> */
    public function findByOrganisation(Organisation $organisation): array
    {
        return $this->findBy(['organisation' => $organisation], ['position' => 'ASC', 'name' => 'ASC']);
    }
}
