<?php

namespace Base\Estate\Entity;

use Base\Estate\Repository\OrganisationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Who owns or manages the homes: an SCI, an agency. Everything else belongs
 * to one; its management team (Member), its associates and its books
 * (omnibase/ledger's Book, by id) hang from it.
 */
#[ORM\Entity(repositoryClass: OrganisationRepository::class)]
#[ORM\Table(name: 'estate_organisation')]
class Organisation
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 160)]
    #[Assert\NotBlank]
    private string $name = '';

    #[ORM\Column(length: 160, unique: true)]
    private string $slug = '';

    /** Its name in the register (Sirene / RNE): the legal notice's, where `name` is the site's. */
    #[ORM\Column(length: 160, nullable: true)]
    private ?string $legalName = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $legalForm = null;

    #[ORM\Column(length: 14, nullable: true)]
    private ?string $siren = null;

    /** Its intra-community VAT number, when VIES knows it: none for a company that is not liable. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $vatNumber = null;

    /** When the registers were last read (Base\Estate\Service\CompanyRecords): null, typed by hand. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $registryCheckedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(length: 12, nullable: true)]
    private ?string $postcode = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    private ?string $email = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $phone = null;

    /** Where tenants send their rent by transfer. */
    #[ORM\Column(length: 34, nullable: true)]
    private ?string $iban = null;

    /** Its books in omnibase/ledger (Base\Ledger\Entity\Book), when the company keeps them here. */
    #[ORM\Column(nullable: true)]
    private ?int $bookId = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $foundedOn = null;

    public function __construct(string $name = '', string $slug = '')
    {
        $this->name = $name;
        $this->slug = $slug;
    }

    public function __toString(): string { return $this->name; }
    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): self { $this->slug = $slug; return $this; }
    public function getLegalName(): ?string { return $this->legalName; }
    public function setLegalName(?string $legalName): self { $this->legalName = $legalName; return $this; }
    public function getVatNumber(): ?string { return $this->vatNumber; }
    public function setVatNumber(?string $vatNumber): self { $this->vatNumber = $vatNumber; return $this; }
    public function getRegistryCheckedAt(): ?\DateTimeImmutable { return $this->registryCheckedAt; }
    public function setRegistryCheckedAt(?\DateTimeImmutable $registryCheckedAt): self { $this->registryCheckedAt = $registryCheckedAt; return $this; }
    public function getLegalForm(): ?string { return $this->legalForm; }
    public function setLegalForm(?string $legalForm): self { $this->legalForm = $legalForm; return $this; }
    public function getSiren(): ?string { return $this->siren; }
    public function setSiren(?string $siren): self { $this->siren = $siren ? preg_replace('/\s+/', '', $siren) : null; return $this; }
    public function getAddress(): ?string { return $this->address; }
    public function setAddress(?string $address): self { $this->address = $address; return $this; }
    public function getPostcode(): ?string { return $this->postcode; }
    public function setPostcode(?string $postcode): self { $this->postcode = $postcode; return $this; }
    public function getCity(): ?string { return $this->city; }
    public function setCity(?string $city): self { $this->city = $city; return $this; }
    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): self { $this->email = $email; return $this; }
    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $phone): self { $this->phone = $phone; return $this; }
    public function getIban(): ?string { return $this->iban; }
    public function setIban(?string $iban): self { $this->iban = $iban ? strtoupper(preg_replace('/\s+/', '', $iban)) : null; return $this; }
    public function getBookId(): ?int { return $this->bookId; }
    public function setBookId(?int $bookId): self { $this->bookId = $bookId; return $this; }
    public function getFoundedOn(): ?\DateTimeImmutable { return $this->foundedOn; }
    public function setFoundedOn(?\DateTimeImmutable $foundedOn): self { $this->foundedOn = $foundedOn; return $this; }

    /** "12 rue …, 67000 Strasbourg" */
    public function getFullAddress(): string
    {
        return trim(implode(', ', array_filter([$this->address, trim($this->postcode.' '.$this->city)])));
    }
}
