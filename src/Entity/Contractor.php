<?php

namespace Base\Estate\Entity;

use Base\Entity\User;
use Doctrine\ORM\Mapping as ORM;

/** A tradesperson the organisation calls on: their company, their trade, and their account once invited. */
#[ORM\Entity]
#[ORM\Table(name: 'estate_contractor')]
class Contractor
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Organisation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Organisation $organisation;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(length: 160)]
    private string $company = '';

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $contactName = null;

    /** "Plomberie", "Électricité", "Serrurerie"... */
    #[ORM\Column(length: 80)]
    private string $trade = '';

    #[ORM\Column(length: 14, nullable: true)]
    private ?string $siret = null;

    #[ORM\Column(length: 180)]
    private string $email = '';

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $phone = null;

    /** Where their invoices are paid. */
    #[ORM\Column(length: 34, nullable: true)]
    private ?string $iban = null;

    public function __construct(Organisation $organisation, string $company = '', string $trade = '', string $email = '')
    {
        $this->organisation = $organisation;
        $this->company = $company;
        $this->trade = $trade;
        $this->email = mb_strtolower(trim($email));
    }

    public function __toString(): string { return $this->company.($this->trade ? ' ('.$this->trade.')' : ''); }
    public function getId(): ?int { return $this->id; }
    public function getOrganisation(): Organisation { return $this->organisation; }
    public function setOrganisation(Organisation $organisation): self { $this->organisation = $organisation; return $this; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): self { $this->user = $user; return $this; }
    public function getCompany(): string { return $this->company; }
    public function setCompany(string $company): self { $this->company = $company; return $this; }
    public function getContactName(): ?string { return $this->contactName; }
    public function setContactName(?string $contactName): self { $this->contactName = $contactName; return $this; }
    public function getTrade(): string { return $this->trade; }
    public function setTrade(string $trade): self { $this->trade = $trade; return $this; }
    public function getSiret(): ?string { return $this->siret; }
    public function setSiret(?string $siret): self { $this->siret = $siret ? preg_replace('/\s+/', '', $siret) : null; return $this; }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): self { $this->email = mb_strtolower(trim($email)); return $this; }
    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $phone): self { $this->phone = $phone; return $this; }
    public function getIban(): ?string { return $this->iban; }
    public function setIban(?string $iban): self { $this->iban = $iban ? strtoupper(preg_replace('/\s+/', '', $iban)) : null; return $this; }
}
