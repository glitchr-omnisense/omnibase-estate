<?php

namespace Base\Estate\Entity;

use Base\Entity\User;
use Base\Estate\Enum\PartyRole;
use Doctrine\ORM\Mapping as ORM;

/**
 * Someone on a lease: a tenant, a co-tenant, a guarantor - and, once invited, their account; a
 * tenant's cloud folder (omnibase/drive: Locataires/<name>, Drive\LeaseFolders).
 */
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

    /** A tenant's cloud folder, shared with them read-only (omnibase/drive), once made. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $folder = null;

    /**
     * A guarantor's commitment, as their deed states it (Signature\SuretySignatures): the limit in
     * principal and accessories (cents) it is given for - written out in words and figures in the
     * statement the guarantor makes (code civil, art. 2297) -, until when (null: no duration
     * stated) and whether it is joint (solidaire: the landlord need not sue the tenant first,
     * nor divide between guarantors).
     */
    #[ORM\Column(nullable: true)]
    private ?int $suretyLimit = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $suretyUntil = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $suretyJoint = true;

    /** The guarantor's postal address, printed on their deed. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $address = null;

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
    public function getFolder(): ?string { return $this->folder; }
    public function setFolder(?string $folder): self { $this->folder = $folder; return $this; }
    public function isGuarantor(): bool { return PartyRole::GUARANTOR === $this->role; }
    public function getSuretyLimit(): ?int { return $this->suretyLimit; }
    public function setSuretyLimit(?int $suretyLimit): self { $this->suretyLimit = null === $suretyLimit || $suretyLimit <= 0 ? null : $suretyLimit; return $this; }
    public function getSuretyUntil(): ?\DateTimeImmutable { return $this->suretyUntil; }
    public function setSuretyUntil(?\DateTimeImmutable $suretyUntil): self { $this->suretyUntil = $suretyUntil; return $this; }
    public function isSuretyJoint(): bool { return $this->suretyJoint; }
    public function setSuretyJoint(?bool $suretyJoint): self { $this->suretyJoint = (bool) $suretyJoint; return $this; }
    public function getAddress(): ?string { return $this->address; }
    public function setAddress(?string $address): self { $this->address = null === $address || '' === trim($address) ? null : trim($address); return $this; }
    /** Who lives in the home: a tenant or a co-tenant, not a guarantor. */
    public function isOccupant(): bool { return PartyRole::GUARANTOR !== $this->role; }
    public function setIban(?string $iban): self { $this->iban = $iban ? strtoupper(preg_replace('/\s+/', '', $iban)) : null; return $this; }
}
