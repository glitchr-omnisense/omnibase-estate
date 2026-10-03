<?php

namespace Base\Estate\Ledger;

use Base\Entity\User;
use Base\Estate\Entity\Organisation;
use Base\Estate\Service\Spaces;
use Base\Ledger\Entity\Book;
use Base\Ledger\Security\BookAccessInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * An associate reads their company's books (omnibase/ledger); its
 * management keeps them. Only loaded with omnibase/ledger.
 */
final class AssociateBookAccess implements BookAccessInterface
{
    public function __construct(
        private readonly Spaces $spaces,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function canView(UserInterface $user, Book $book): bool
    {
        $organisation = $this->organisation($book);

        return $user instanceof User && $organisation && ($this->spaces->isAssociateOf($user, $organisation) || $this->spaces->manages($user, $organisation));
    }

    public function canManage(UserInterface $user, Book $book): bool
    {
        $organisation = $this->organisation($book);

        return $user instanceof User && $organisation && $this->spaces->manages($user, $organisation);
    }

    private function organisation(Book $book): ?Organisation
    {
        return $this->entityManager->getRepository(Organisation::class)->findOneBy(['bookId' => $book->getId()]);
    }
}
