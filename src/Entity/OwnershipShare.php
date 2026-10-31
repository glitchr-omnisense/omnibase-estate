<?php

namespace Base\Estate\Entity;

use Base\Entity\User;
use Base\Estate\Model\Shares;
use Doctrine\ORM\Mapping as ORM;

/**
 * A co-owner's share of a shared ownership, from a date: a person (their
 * name and e-mail; their account, once they have one, opens the co-owner's
 * space), one of the company's associates, or the managing company itself;
 * an exact fraction (1/3: a numerator and a denominator) and the IBAN their
 * payouts go to. A share sold or inherited ends (endsOn, the first day it
 * no longer holds) and the new holder's starts that day.
 *
 * The same co-owner over time - a share grown from 1/4 to 1/2 - is one
 * holder (holder(): the company, else their e-mail): one account in the
 * books, one statement.
 */
#[ORM\Entity]
#[ORM\Table(name: 'estate_ownership_share')]
class OwnershipShare
{
    public const COMPANY = 'company';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Ownership::class, inversedBy: 'shares')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Ownership $ownership;

    /** The managing company itself holds this share: its part stays in its own accounts. */
    #[ORM\Column]
    private bool $company = false;

    #[ORM\ManyToOne(targetEntity: Associate::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Associate $associate = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(length: 160)]
    private string $name = '';

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 34, nullable: true)]
    private ?string $iban = null;

    #[ORM\Column]
    private int $numerator = 1;

    #[ORM\Column]
    private int $denominator = 1;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $startsOn;

    /** The first day it no longer holds (sold, inherited); null: still held. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $endsOn = null;

    public function __construct(?Ownership $ownership = null, string $name = '', int $numerator = 1, int $denominator = 1, ?\DateTimeImmutable $startsOn = null)
    {
        if ($ownership) {
            $this->ownership = $ownership;
            $ownership->addShare($this);
        }
        $this->name = $name;
        $this->setFraction($numerator, $denominator);
        $this->startsOn = ($startsOn ?? new \DateTimeImmutable('today'))->setTime(0, 0);
    }

    public function __toString(): string { return sprintf('%s (%s)', $this->name, $this->getFractionLabel()); }
    public function getId(): ?int { return $this->id; }
    public function getOwnership(): Ownership { return $this->ownership; }
    public function setOwnership(Ownership $ownership): self { $this->ownership = $ownership; return $this; }
    public function isCompany(): bool { return $this->company; }
    public function setCompany(bool $company): self { $this->company = $company; if ($company && '' === $this->name && isset($this->ownership)) { $this->name = $this->ownership->getOrganisation()->getName(); } return $this; }
    public function getAssociate(): ?Associate { return $this->associate; }

    public function setAssociate(?Associate $associate): self
    {
        $this->associate = $associate;
        if (null !== $associate) {
            $this->name = '' !== $this->name ? $this->name : $associate->getName();
            $this->email ??= $associate->getEmail() ?: null;
            $this->user ??= $associate->getUser();
        }

        return $this;
    }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): self { $this->user = $user; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(?string $name): self { $this->name = trim((string) $name); return $this; }
    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): self { $email = mb_strtolower(trim((string) $email)); $this->email = '' === $email ? null : $email; return $this; }
    public function getIban(): ?string { return $this->iban; }
    public function setIban(?string $iban): self { $iban = strtoupper(preg_replace('/\s+/', '', (string) $iban)); $this->iban = '' === $iban ? null : $iban; return $this; }
    public function getNumerator(): int { return $this->numerator; }
    public function setNumerator(?int $numerator): self { return $this->setFraction((int) $numerator, $this->denominator); }
    public function getDenominator(): int { return $this->denominator; }
    public function setDenominator(?int $denominator): self { return $this->setFraction($this->numerator, (int) $denominator ?: 1); }
    public function getStartsOn(): \DateTimeImmutable { return $this->startsOn; }
    public function setStartsOn(?\DateTimeImmutable $startsOn): self { $this->startsOn = ($startsOn ?? new \DateTimeImmutable('today'))->setTime(0, 0); return $this; }
    public function getEndsOn(): ?\DateTimeImmutable { return $this->endsOn; }
    public function setEndsOn(?\DateTimeImmutable $endsOn): self { $this->endsOn = $endsOn?->setTime(0, 0); return $this; }

    /** Kept as typed (2/8 stays 2/8 on the screen); compared and summed in lowest terms. */
    public function setFraction(int $numerator, int $denominator): self
    {
        if ($numerator < 0 || $denominator <= 0 || $numerator > $denominator) {
            throw new \InvalidArgumentException(sprintf('A share is a fraction between 0 and 1, not %d/%d.', $numerator, $denominator));
        }
        $this->numerator = $numerator;
        $this->denominator = $denominator;

        return $this;
    }

    /** @return array{0: int, 1: int} */
    public function fraction(): array
    {
        return Shares::reduce($this->numerator, $this->denominator);
    }

    public function getFractionLabel(): string
    {
        return Shares::format($this->numerator, $this->denominator);
    }

    public function getPercent(): float
    {
        return Shares::percent($this->numerator, $this->denominator);
    }

    public function isInForce(\DateTimeInterface $on): bool
    {
        $day = $on->format('Y-m-d');

        return $this->startsOn->format('Y-m-d') <= $day && (null === $this->endsOn || $this->endsOn->format('Y-m-d') > $day);
    }

    /**
     * Who holds it, the same across their shares over time: "company" for
     * the managing company, else "email:<their e-mail>" - or, without one,
     * "share:<id>" (this share alone).
     */
    public function holder(): string
    {
        if ($this->company) {
            return self::COMPANY;
        }
        if (null !== $this->email) {
            return 'email:'.$this->email;
        }

        return 'share:'.($this->id ?? spl_object_id($this));
    }
}
