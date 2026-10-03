<?php

namespace Base\Estate\Entity;

use Base\Database\Attribute\Uploader;
use Base\Entity\User;
use Base\Estate\Enum\AssociateQuality;
use Doctrine\ORM\Mapping as ORM;

/**
 * A partner of the company: their shares, their quality, and - once they
 * accepted their invitation - the account that opens the associates' space.
 * Shown on the public site only when they agreed to.
 */
#[ORM\Entity]
#[ORM\Table(name: 'estate_associate')]
class Associate
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Organisation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Organisation $organisation;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(length: 80)]
    private string $firstName = '';

    #[ORM\Column(length: 80)]
    private string $lastName = '';

    #[ORM\Column(length: 180)]
    private string $email = '';

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column]
    private int $shares = 0;

    #[ORM\Column(length: 16, enumType: AssociateQuality::class)]
    private AssociateQuality $quality = AssociateQuality::ASSOCIATE;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $since = null;

    /** Shown on the public site ("La société"): only when they agreed to. */
    #[ORM\Column]
    private bool $public = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $bio = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Uploader(max_size: '4MB', mime_types: ['image/*'], missable: true)]
    private $portrait = null;

    public function __construct(Organisation $organisation, string $firstName = '', string $lastName = '', string $email = '')
    {
        $this->organisation = $organisation;
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->email = mb_strtolower(trim($email));
    }

    public function __toString(): string { return $this->getName(); }
    public function getId(): ?int { return $this->id; }
    public function getOrganisation(): Organisation { return $this->organisation; }
    public function setOrganisation(Organisation $organisation): self { $this->organisation = $organisation; return $this; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): self { $this->user = $user; return $this; }
    public function getFirstName(): string { return $this->firstName; }
    public function setFirstName(string $firstName): self { $this->firstName = $firstName; return $this; }
    public function getLastName(): string { return $this->lastName; }
    public function setLastName(string $lastName): self { $this->lastName = $lastName; return $this; }
    public function getName(): string { return trim($this->firstName.' '.$this->lastName); }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): self { $this->email = mb_strtolower(trim($email)); return $this; }
    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $phone): self { $this->phone = $phone; return $this; }
    public function getShares(): int { return $this->shares; }
    public function setShares(?int $shares): self { $this->shares = max(0, (int) $shares); return $this; }
    public function getQuality(): AssociateQuality { return $this->quality; }
    public function setQuality(AssociateQuality $quality): self { $this->quality = $quality; return $this; }
    public function getSince(): ?\DateTimeImmutable { return $this->since; }
    public function setSince(?\DateTimeImmutable $since): self { $this->since = $since; return $this; }
    public function isPublic(): bool { return $this->public; }
    public function setPublic(bool $public): self { $this->public = $public; return $this; }
    public function getBio(): ?string { return $this->bio; }
    public function setBio(?string $bio): self { $this->bio = $bio; return $this; }
    public function getPortrait(): ?string { return Uploader::getPublic($this, 'portrait'); }
    public function getPortraitFile() { return Uploader::get($this, 'portrait'); }
    public function setPortrait($portrait): self { $this->portrait = $portrait ?: null; return $this; }
}
