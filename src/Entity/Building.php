<?php

namespace Base\Estate\Entity;

use Doctrine\ORM\Mapping as ORM;

/** A building some units are in: its address, its year, its common parts. */
#[ORM\Entity]
#[ORM\Table(name: 'estate_building')]
class Building
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Organisation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Organisation $organisation;

    #[ORM\Column(length: 160)]
    private string $name = '';

    #[ORM\Column(length: 255)]
    private string $address = '';

    #[ORM\Column(length: 12)]
    private string $postcode = '';

    #[ORM\Column(length: 120)]
    private string $city = '';

    #[ORM\Column(nullable: true)]
    private ?int $builtIn = null;

    /** How to get in (digicode, keys): told to the contractors sent there, never on the public site. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $access = null;

    public function __construct(Organisation $organisation, string $name = '')
    {
        $this->organisation = $organisation;
        $this->name = $name;
    }

    public function __toString(): string { return $this->name; }
    public function getId(): ?int { return $this->id; }
    public function getOrganisation(): Organisation { return $this->organisation; }
    public function setOrganisation(Organisation $organisation): self { $this->organisation = $organisation; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getAddress(): string { return $this->address; }
    public function setAddress(string $address): self { $this->address = $address; return $this; }
    public function getPostcode(): string { return $this->postcode; }
    public function setPostcode(string $postcode): self { $this->postcode = $postcode; return $this; }
    public function getCity(): string { return $this->city; }
    public function setCity(string $city): self { $this->city = $city; return $this; }
    public function getBuiltIn(): ?int { return $this->builtIn; }
    public function setBuiltIn(?int $builtIn): self { $this->builtIn = $builtIn; return $this; }
    public function getAccess(): ?string { return $this->access; }
    public function setAccess(?string $access): self { $this->access = $access; return $this; }
}
