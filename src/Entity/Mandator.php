<?php

namespace Base\Estate\Entity;

use Base\Entity\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * An owner who entrusts homes to the agency (the mandant of a management
 * mandate): a person or a company, how to reach them, the IBAN their
 * payouts go to, their account once they have one - it opens the owner's
 * space -, their cloud folder (omnibase/drive).
 *
 * Named after the mandate's legal party - "owner" alone would say nothing
 * of the agency's side, and Ownership is the shared ownership already.
 * Its screens and postings exist when the site runs the `management`
 * activity (estate.activities).
 */
#[ORM\Entity]
#[ORM\Table(name: 'estate_mandator')]
class Mandator
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 160)]
    private string $name = '';

    /** A company (a property company, a business) rather than a person. */
    #[ORM\Column]
    private bool $company = false;

    /** A company's SIREN. */
    #[ORM\Column(length: 14, nullable: true)]
    private ?string $siren = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(length: 12, nullable: true)]
    private ?string $postcode = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $city = null;

    /** Where their payouts go. */
    #[ORM\Column(length: 34, nullable: true)]
    private ?string $iban = null;

    /** Their bank's BIC, when known: a SEPA transfer goes without it. */
    #[ORM\Column(length: 11, nullable: true)]
    private ?string $bic = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    /** Their cloud folder, shared with them read-only (omnibase/drive), once made. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $folder = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name = '')
    {
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string { return $this->name; }
    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(?string $name): self { $this->name = trim((string) $name); return $this; }
    public function isCompany(): bool { return $this->company; }
    public function setCompany(?bool $company): self { $this->company = (bool) $company; return $this; }
    public function getSiren(): ?string { return $this->siren; }
    public function setSiren(?string $siren): self { $siren = preg_replace('/\s+/', '', (string) $siren); $this->siren = '' === $siren ? null : $siren; return $this; }
    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): self { $email = mb_strtolower(trim((string) $email)); $this->email = '' === $email ? null : $email; return $this; }
    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $phone): self { $this->phone = '' !== trim((string) $phone) ? trim((string) $phone) : null; return $this; }
    public function getAddress(): ?string { return $this->address; }
    public function setAddress(?string $address): self { $this->address = '' !== trim((string) $address) ? trim((string) $address) : null; return $this; }
    public function getPostcode(): ?string { return $this->postcode; }
    public function setPostcode(?string $postcode): self { $this->postcode = '' !== trim((string) $postcode) ? trim((string) $postcode) : null; return $this; }
    public function getCity(): ?string { return $this->city; }
    public function setCity(?string $city): self { $this->city = '' !== trim((string) $city) ? trim((string) $city) : null; return $this; }
    public function getIban(): ?string { return $this->iban; }
    public function setIban(?string $iban): self { $iban = strtoupper(preg_replace('/\s+/', '', (string) $iban)); $this->iban = '' === $iban ? null : $iban; return $this; }
    public function getBic(): ?string { return $this->bic; }
    public function setBic(?string $bic): self { $bic = strtoupper(preg_replace('/\s+/', '', (string) $bic)); $this->bic = '' === $bic ? null : $bic; return $this; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): self { $this->user = $user; return $this; }
    public function getFolder(): ?string { return $this->folder; }
    public function setFolder(?string $folder): self { $this->folder = $folder; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self { $this->notes = '' !== trim((string) $notes) ? trim((string) $notes) : null; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getFullAddress(): string
    {
        return trim(implode(', ', array_filter([$this->address, trim($this->postcode.' '.$this->city)])), ', ');
    }
}
