<?php

namespace Base\Estate\Repository;

use Base\Estate\Entity\Lease;
use Base\Estate\Entity\Message;
use Base\Estate\Entity\Organisation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Message> */
class MessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
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
        $messages = $this->createQueryBuilder('m')->join('m.lease', 'l')->join('l.unit', 'u')
            ->where('u.organisation = :organisation')->setParameter('organisation', $organisation)
            ->orderBy('m.createdAt', 'DESC')->addOrderBy('m.id', 'DESC')
            ->getQuery()->getResult();
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

    /** Tenant messages the management has not read yet, for the back office's badge. */
    public function unreadByManagement(Organisation $organisation): int
    {
        return (int) $this->createQueryBuilder('m')->select('COUNT(m.id)')->join('m.lease', 'l')->join('l.unit', 'u')
            ->where('u.organisation = :organisation')->setParameter('organisation', $organisation)
            ->andWhere('m.fromManagement = false AND m.readAt IS NULL')
            ->getQuery()->getSingleScalarResult();
    }
}
