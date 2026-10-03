<?php

namespace Base\Estate\Entity;

use Base\Entity\User;
use Base\Estate\Enum\PartyRole;
use Doctrine\ORM\Mapping as ORM;

/** Someone on a lease: a tenant, a co-tenant, a guarantor - and, once invited, their account. */
#[ORM\Entity]
#[ORM\Table(name: 'estate_lease_party')]
class LeaseParty
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Lease::class, inversedBy: 'parties')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Lease $lease = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(length: 16, enumType: PartyRole::class)]
    private PartyRole $role = PartyRole::TENANT;

    #[ORM\Column(length: 80)]
    private string $firstName = '';

    #[ORM\Column(length: 80)]
    private string $lastName = '';

    #[ORM\Column(length: 180)]
    private string $email = '';

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $phone = null;

    /** The IBAN their transfers come from: how the bank line is recognised. */
    #[ORM\Column(length: 34, nullable: true)]
    private ?string $iban = null;

    public function __construct(string $firstName = '', string $lastName = '', string $email = '', PartyRole $role = PartyRole::TENANT)
    {
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->email = mb_strtolower(trim($email));
        $this->role = $role;
    }

    public function __toString(): string { return $this->getName(); }
    public function getId(): ?int { return $this->id; }
    public function getLease(): ?Lease { return $this->lease; }
    public function setLease(?Lease $lease): self { $this->lease = $lease; return $this; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): self { $this->user = $user; return $this; }
    public function getRole(): PartyRole { return $this->role; }
    public function setRole(PartyRole $role): self { $this->role = $role; return $this; }
    public function getFirstName(): string { return $this->firstName; }
    public function setFirstName(string $firstName): self { $this->firstName = $firstName; return $this; }
    public function getLastName(): string { return $this->lastName; }
    public function setLastName(string $lastName): self { $this->lastName = $lastName; return $this; }
    public function getName(): string { return trim($this->firstName.' '.$this->lastName); }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): self { $this->email = mb_strtolower(trim($email)); return $this; }
    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $phone): self { $this->phone = $phone; return $this; }
    public function getIban(): ?string { return $this->iban; }
    public function setIban(?string $iban): self { $this->iban = $iban ? strtoupper(preg_replace('/\s+/', '', $iban)) : null; return $this; }
}
