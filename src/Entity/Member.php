<?php

namespace Base\Estate\Entity;

use Base\Entity\User;
use Doctrine\ORM\Mapping as ORM;

/** Someone of the management team of an organisation: they run its back office. */
#[ORM\Entity]
#[ORM\Table(name: 'estate_member')]
#[ORM\UniqueConstraint(name: 'estate_member_unique', columns: ['organisation_id', 'user_id'])]
class Member
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Organisation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Organisation $organisation;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 120)]
    private string $name = '';

    #[ORM\Column(length: 180)]
    private string $email = '';

    /** "Gestionnaire", "Comptable"... */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $title = null;

    public function __construct(Organisation $organisation, string $name = '', string $email = '')
    {
        $this->organisation = $organisation;
        $this->name = $name;
        $this->email = $email;
    }

    public function __toString(): string { return $this->name; }
    public function getId(): ?int { return $this->id; }
    public function getOrganisation(): Organisation { return $this->organisation; }
    public function setOrganisation(Organisation $organisation): self { $this->organisation = $organisation; return $this; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): self { $this->user = $user; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): self { $this->email = mb_strtolower(trim($email)); return $this; }
    public function getTitle(): ?string { return $this->title; }
    public function setTitle(?string $title): self { $this->title = $title; return $this; }
}
