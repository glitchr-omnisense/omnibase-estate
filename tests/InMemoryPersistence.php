<?php

namespace Tests\Base\Estate;

use Base\Contract\UnitOfWork;
use Base\Database\Repository\Entities;
use Base\Database\Repository\Finder\Key;
use Base\Database\Repository\Repositories;

/**
 * The persistence of a unit test, in memory: the core's contracts a service is given in place of an ORM - its
 * UnitOfWork (persist, remove, flush) and its Repositories (of($class), the entities of a class) - over arrays of
 * records by class. No database, no backend: the same test runs whatever persistence the application chose.
 *
 * - $records (by reference): class => list of entities, what persist() adds to and remove() takes from;
 * - $identify: called on every persisted entity (give it an id);
 * - $repositories: class => the Entities answering of($class) (a typed repository's stub), else a plain
 *   in-memory one matching criteria field by field through the getters (get<Field>()/is<Field>(); a list is IN, a
 *   Key::of() compares its value);
 * - $find: (class, id) => entity, for find() and oneById() when the records do not say it.
 *
 * $flushes counts the flushes.
 */
final class InMemoryPersistence implements UnitOfWork, Repositories
{
    public int $flushes = 0;

    /** @var array<class-string, list<object>> */
    private array $records;

    /**
     * @param array<class-string, list<object>> $records
     * @param array<class-string, Entities>     $repositories
     */
    public function __construct(
        array &$records = [],
        private readonly ?\Closure $identify = null,
        private array $repositories = [],
        private readonly ?\Closure $find = null,
    ) {
        $this->records = &$records;
    }

    public function persist(object $entity): void
    {
        if (null !== $this->identify) {
            ($this->identify)($entity);
        }
        if (!\in_array($entity, $this->records[$entity::class] ?? [], true)) {
            $this->records[$entity::class][] = $entity;
        }
    }

    public function remove(object $entity): void
    {
        $this->records[$entity::class] = array_values(array_filter($this->records[$entity::class] ?? [], static fn (object $record) => $record !== $entity));
    }

    public function flush(): void
    {
        ++$this->flushes;
    }

    public function refresh(object $entity): void
    {
    }

    public function contains(object $entity): bool
    {
        return \in_array($entity, $this->records[$entity::class] ?? [], true);
    }

    public function reference(string $class, mixed $id): object
    {
        return $this->oneById($class, $id) ?? throw new \LogicException(sprintf('No %s #%s in memory.', $class, (string) $id));
    }

    public function transactional(callable $work): mixed
    {
        $result = $work($this);
        $this->flush();

        return $result;
    }

    public function isNew(object $entity): bool
    {
        return !$this->contains($entity) || (method_exists($entity, 'getId') && null === $entity->getId());
    }

    public function identifier(object $entity): mixed
    {
        return method_exists($entity, 'getId') ? $entity->getId() : null;
    }

    public function className(object $entity): string
    {
        return $entity::class;
    }

    public function changes(object $entity): array
    {
        return [];
    }

    public function recompute(object $entity): void
    {
    }

    public function clear(): void
    {
    }

    public function isOpen(): bool
    {
        return true;
    }

    public function of(string $class): Entities
    {
        return $this->repositories[$class] ??= new InMemoryEntities($this, $class);
    }

    public function byIds(string $class, array $ids): array
    {
        return array_values(array_filter(array_map(fn ($id) => $this->oneById($class, $id), $ids)));
    }

    public function oneById(string $class, mixed $id): ?object
    {
        if (null !== $this->find && null !== $found = ($this->find)($class, $id)) {
            return $found;
        }

        return $this->matching($class, ['id' => $id])[0] ?? null;
    }

    /**
     * The records of a class matching criteria field by field (Base\... and App\... alike).
     *
     * @return list<object>
     */
    public function matching(string $class, array $criteria): array
    {
        $records = [];
        foreach ($this->records as $recordClass => $list) {
            if ($recordClass === $class || is_a($recordClass, $class, true)) {
                array_push($records, ...$list);
            }
        }

        return array_values(array_filter($records, static function (object $record) use ($criteria): bool {
            foreach ($criteria as $field => $value) {
                $value = $value instanceof Key ? $value->value : $value;
                $getter = method_exists($record, 'get'.ucfirst($field)) ? 'get'.ucfirst($field) : 'is'.ucfirst($field);
                if (!method_exists($record, $getter)) {
                    return false;
                }
                $actual = $record->$getter();
                if (\is_array($value) ? !\in_array($actual, $value, \is_object($actual)) : ('id' === $field ? $actual != $value : $actual !== $value)) {
                    return false;
                }
            }

            return true;
        }));
    }
}
