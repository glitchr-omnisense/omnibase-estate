<?php

namespace Base\Estate\Repository;

use Base\Estate\Entity\Lease;
use Base\Estate\Entity\Message;
use Base\Estate\Entity\Organisation;
use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Parser;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<Message> */
class MessageRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, Message::class);
    }

    /** @return list<Message> a lease's thread, oldest first */
    public function thread(Lease $lease): array
    {
        return $this->findBy(['lease' => $lease], ['createdAt' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * The threads of a company's leases, the latest first, with how many tenant messages the
     * management has not read.
     *
     * @return list<array{lease: Lease, last: Message, unread: int}>
     */
    public function threads(Organisation $organisation): array
    {
        $messages = $this->findBy(['lease.unit.organisation' => $organisation], ['createdAt' => 'DESC', 'id' => 'DESC']);
        $threads = [];
        foreach ($messages as $message) {
            $id = $message->getLease()->getId();
            $threads[$id] ??= ['lease' => $message->getLease(), 'last' => $message, 'unread' => 0];
            if (!$message->isFromManagement() && null === $message->getReadAt()) {
                ++$threads[$id]['unread'];
            }
        }

        return array_values($threads);
    }

    /**
     * unreadByManagement() for several organisations: one count each (the finder language has no GROUP BY).
     *
     * @param list<Organisation> $organisations
     *
     * @return array<int, int> by organisation id, the organisations with unread messages only
     */
    public function unreadByOrganisation(array $organisations): array
    {
        if ([] === $organisations) {
            return [];
        }
        $counts = [];
        foreach ($organisations as $organisation) {
            if (0 < $count = $this->unreadByManagement($organisation)) {
                $counts[(int) $organisation->getId()] = $count;
            }
        }

        return $counts;
    }

    /** Tenant messages the management has not read yet, for the back office's badge. */
    public function unreadByManagement(Organisation $organisation): int
    {
        return $this->count([
            'lease.unit.organisation' => $organisation,
            'fromManagement' => false,
            Group::of(Parser::SEPARATOR_AND, Criterion::of('readAt', null, Parser::OPTION_NULL)),
        ]);
    }
}
