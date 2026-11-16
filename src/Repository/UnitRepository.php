<?php

namespace Base\Estate\Repository;

use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\Unit;
use Base\Estate\Enum\LeaseStatus;
use Base\Estate\Enum\UnitKind;
use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Key;
use Base\Database\Repository\Finder\Parser;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<Unit> */
class UnitRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, Unit::class);
    }

    /** @return list<Unit> what the public site lists: published, free ones first */
    public function findPublished(?string $kind = null, bool $availableOnly = false): array
    {
        $criteria = ['published' => true];
        if ($kind) {
            $criteria['kind'] = $kind;
        }
        if ($availableOnly) {
            $criteria[] = Group::of(Parser::SEPARATOR_AND, Criterion::of('availableFrom', null, Parser::OPTION_NOT_NULL));
        }

        return $this->findBy($criteria, ['availableFrom' => 'DESC', 'position' => 'ASC', 'name' => 'ASC']);
    }

    /**
     * The homes of these organisations - every unit but the parking spaces - each with the lease
     * running on a day, as Unit::getCurrentLease() picks it (not ended, started, not past its
     * end; the latest started first): scalars, the organisations by name, then each one's homes
     * in their order. lease, notice, endOn and monthly are null for a home not let.
     *
     * The homes are read by the finder language, each one's lease by Unit::getCurrentLease()
     * itself (the language has no join with a condition of its own, nor a select of expressions).
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
        $homes = [];
        foreach ($this->findBy([
            'organisation' => Key::of($organisations),
            Group::of(Parser::SEPARATOR_AND, Criterion::of('kind', UnitKind::PARKING, Parser::OPTION_NOT_EQUAL)),
        ], ['organisation.name' => 'ASC', 'organisation.id' => 'ASC', 'position' => 'ASC', 'name' => 'ASC', 'id' => 'ASC']) as $unit) {
            $lease = $unit->getCurrentLease($on);
            $homes[] = [
                'unit' => (int) $unit->getId(),
                'name' => (string) $unit->getName(),
                'lease' => null !== $lease ? (int) $lease->getId() : null,
                'notice' => null !== $lease ? LeaseStatus::NOTICE === $lease->getStatus() : null,
                'endOn' => $lease?->getEndOn(),
                'monthly' => null !== $lease ? $lease->getRent() + $lease->getCharges() : null,
            ];
        }

        return $homes;
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
        // The leases and their parties load with the units' collections, in their own order (Unit::$leases'
        // latest started first, the parties as entered): the finder language does not select joined rows.
        foreach ($this->findBy(['id' => $ids]) as $unit) {
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
