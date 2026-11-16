<?php

namespace Tests\Base\Estate;

use Base\Database\Repository\Entities;

/** The entities of one class in an InMemoryPersistence: findBy() and friends over its records. */
final class InMemoryEntities implements Entities
{
    public function __construct(private readonly InMemoryPersistence $persistence, private readonly string $class)
    {
    }

    public function find($id): ?object
    {
        return $this->persistence->oneById($this->class, $id);
    }

    public function findAll(): array
    {
        return $this->persistence->matching($this->class, []);
    }

    public function findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null): array
    {
        return \array_slice($this->persistence->matching($this->class, $criteria), (int) $offset, $limit);
    }

    public function findOneBy(array $criteria, ?array $orderBy = null): ?object
    {
        return $this->persistence->matching($this->class, $criteria)[0] ?? null;
    }

    public function count(array $criteria = []): int
    {
        return \count($this->persistence->matching($this->class, $criteria));
    }

    public function persist($entity, bool $flush = false): void
    {
        $this->persistence->persist($entity);
        if ($flush) {
            $this->persistence->flush();
        }
    }

    public function remove($entity, bool $flush = false): void
    {
        $this->persistence->remove($entity);
        if ($flush) {
            $this->persistence->flush();
        }
    }

    public function flush(bool $autoclear = false)
    {
        $this->persistence->flush();
    }

    public function clear(): void
    {
    }
}
