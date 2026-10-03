<?php

namespace Base\Estate\Entity;

use Base\Entity\User;
use Base\Estate\Repository\MessageRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A message between a lease's tenants and the management - one thread per
 * lease, in the tenant's space and in the back office (Messages), each new
 * one sent by e-mail to the other side.
 */
#[ORM\Entity(repositoryClass: MessageRepository::class)]
#[ORM\Table(name: 'estate_message')]
#[ORM\Index(columns: ['createdAt'])]
class Message
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Lease::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Lease $lease;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    /** Who wrote it, as shown: "Léa Muller", "Gestion". */
    #[ORM\Column(length: 160)]
    private string $authorName = '';

    /** Written by the management (to the tenants), or by a tenant (to the management). */
    #[ORM\Column]
    private bool $fromManagement = false;

    #[ORM\Column(type: 'text')]
    private string $body = '';

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** When the other side read it. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    public function __construct(Lease $lease, string $authorName, string $body, bool $fromManagement, ?User $author = null)
    {
        $this->lease = $lease;
        $this->authorName = $authorName;
        $this->body = $body;
        $this->fromManagement = $fromManagement;
        $this->author = $author;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getLease(): Lease { return $this->lease; }
    public function getAuthor(): ?User { return $this->author; }
    public function getAuthorName(): string { return $this->authorName; }
    public function isFromManagement(): bool { return $this->fromManagement; }
    public function getBody(): string { return $this->body; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getReadAt(): ?\DateTimeImmutable { return $this->readAt; }
    public function markRead(): self { $this->readAt ??= new \DateTimeImmutable(); return $this; }
}
