<?php

namespace Base\Estate\Service;

use Base\Entity\User;
use Base\Estate\Entity\Lease;
use Base\Estate\Entity\Message;
use Base\Estate\Repository\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The thread between a lease's tenants and the management: a message is kept,
 * then e-mailed to the other side - the management's address, or every tenant
 * of the lease. Reading a thread marks the other side's messages read.
 */
class Messages
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageRepository $messages,
        private readonly EstateMailer $mailer,
    ) {
    }

    public function post(Lease $lease, string $body, bool $fromManagement, ?User $author, string $authorName): Message
    {
        $message = new Message($lease, $authorName, trim($body), $fromManagement, $author);
        $this->entityManager->persist($message);
        $this->entityManager->flush();

        $organisation = $lease->getOrganisation();
        $to = $fromManagement
            ? array_values(array_filter(array_map(fn ($party) => $party->getEmail(), $lease->getTenants())))
            : $organisation->getEmail();
        $this->mailer->send($to, 'Message — '.$lease->getUnit()->getName(), 'message', ['message' => $message, 'lease' => $lease, 'organisation' => $organisation], $fromManagement ? $organisation->getEmail() : null);

        return $message;
    }

    /** The thread as one side reads it: the other side's messages marked read. */
    public function read(Lease $lease, bool $byManagement): array
    {
        $thread = $this->messages->thread($lease);
        $changed = false;
        foreach ($thread as $message) {
            if ($message->isFromManagement() !== $byManagement && null === $message->getReadAt()) {
                $message->markRead();
                $changed = true;
            }
        }
        if ($changed) {
            $this->entityManager->flush();
        }

        return $thread;
    }
}
