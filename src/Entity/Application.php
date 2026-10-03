<?php

namespace Base\Estate\Entity;

use Base\Estate\Enum\ApplicationStatus;
use Base\Estate\Enum\Guarantee;
use Base\Estate\Enum\Occupation;
use Base\Estate\Repository\ApplicationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Someone applying to rent a free home, from its page: who they are, how many they would
 * be, when they would move in, their situation and income, their guarantee, and the link to
 * their file on DossierFacile (the State's service: identity papers, payslips and tax notice
 * verified there, never sent to the site). Kept three months after the home is let or the
 * application declined (Applications::purge, as the CNIL recommends), then deleted.
 */
#[ORM\Entity(repositoryClass: ApplicationRepository::class)]
#[ORM\Table(name: 'estate_application')]
#[ORM\Index(columns: ['createdAt'])]
class Application
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Unit::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Unit $unit;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank, Assert\Length(max: 80)]
    private string $firstName = '';

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank, Assert\Length(max: 80)]
    private string $lastName = '';

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank, Assert\Email]
    private string $email = '';

    #[ORM\Column(length: 40, nullable: true)]
    #[Assert\Length(max: 40)]
    private ?string $phone = null;

    /** How many would live there, children included. */
    #[ORM\Column]
    #[Assert\Range(min: 1, max: 12)]
    private int $occupants = 1;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $moveInOn = null;

    #[ORM\Column(length: 20, enumType: Occupation::class)]
    private Occupation $occupation = Occupation::PERMANENT;

    /** The household's net monthly income, in cents. */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $income = 0;

    #[ORM\Column(length: 20, enumType: Guarantee::class)]
    private Guarantee $guarantee = Guarantee::NONE;

    /** The applicant's file on DossierFacile (https://www.dossierfacile.logement.gouv.fr/file/…). */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Url(requireTld: true), Assert\Length(max: 255)]
    private ?string $dossierUrl = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 3000)]
    private ?string $message = null;

    #[ORM\Column(length: 20, enumType: ApplicationStatus::class)]
    private ApplicationStatus $status = ApplicationStatus::NEW;

    /** The management's own notes - never shown to the applicant. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $visitAt = null;

    /** The lease it became, once accepted. */
    #[ORM\ManyToOne(targetEntity: Lease::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Lease $lease = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** When it was accepted or declined: the three months of keeping start then. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    public function __construct(?Unit $unit = null)
    {
        if ($unit) {
            $this->unit = $unit;
        }
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string { return $this->getName().' — '.$this->unit->getName(); }
    public function getId(): ?int { return $this->id; }
    public function getUnit(): Unit { return $this->unit; }
    public function setUnit(Unit $unit): self { $this->unit = $unit; return $this; }
    public function getOrganisation(): Organisation { return $this->unit->getOrganisation(); }
    public function getFirstName(): string { return $this->firstName; }
    public function setFirstName(?string $firstName): self { $this->firstName = trim((string) $firstName); return $this; }
    public function getLastName(): string { return $this->lastName; }
    public function setLastName(?string $lastName): self { $this->lastName = trim((string) $lastName); return $this; }
    public function getName(): string { return trim($this->firstName.' '.$this->lastName); }
    public function getEmail(): string { return $this->email; }
    public function setEmail(?string $email): self { $this->email = mb_strtolower(trim((string) $email)); return $this; }
    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $phone): self { $this->phone = $phone ?: null; return $this; }
    public function getOccupants(): int { return $this->occupants; }
    public function setOccupants(?int $occupants): self { $this->occupants = max(1, (int) $occupants); return $this; }
    public function getMoveInOn(): ?\DateTimeImmutable { return $this->moveInOn; }
    public function setMoveInOn(?\DateTimeImmutable $moveInOn): self { $this->moveInOn = $moveInOn; return $this; }
    public function getOccupation(): Occupation { return $this->occupation; }
    public function setOccupation(Occupation $occupation): self { $this->occupation = $occupation; return $this; }
    public function getIncome(): int { return $this->income; }
    public function setIncome(?int $income): self { $this->income = (int) $income; return $this; }
    public function getGuarantee(): Guarantee { return $this->guarantee; }
    public function setGuarantee(Guarantee $guarantee): self { $this->guarantee = $guarantee; return $this; }
    public function getDossierUrl(): ?string { return $this->dossierUrl; }
    public function setDossierUrl(?string $dossierUrl): self { $this->dossierUrl = $dossierUrl ? trim($dossierUrl) : null; return $this; }
    public function getMessage(): ?string { return $this->message; }
    public function setMessage(?string $message): self { $this->message = $message ?: null; return $this; }
    public function getStatus(): ApplicationStatus { return $this->status; }
    public function setStatus(ApplicationStatus $status): self
    {
        $this->status = $status;
        if (!$status->isOpen()) {
            $this->decidedAt ??= new \DateTimeImmutable();
        }

        return $this;
    }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self { $this->notes = $notes ?: null; return $this; }
    public function getVisitAt(): ?\DateTimeImmutable { return $this->visitAt; }
    public function setVisitAt(?\DateTimeImmutable $visitAt): self { $this->visitAt = $visitAt; return $this; }
    public function getLease(): ?Lease { return $this->lease; }
    public function setLease(?Lease $lease): self { $this->lease = $lease; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getDecidedAt(): ?\DateTimeImmutable { return $this->decidedAt; }

    /** The monthly rent the home asks, charges included, in cents. */
    public function getAsked(): int { return $this->unit->getRent() + $this->unit->getCharges(); }

    /** Income over rent, charges included: landlords look for about 3. */
    public function getIncomeRatio(): ?float
    {
        return $this->getAsked() > 0 ? round($this->income / $this->getAsked(), 1) : null;
    }
}
