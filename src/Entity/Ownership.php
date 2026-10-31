<?php

namespace Base\Estate\Entity;

use Base\Estate\Model\Shares;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A shared ownership (indivision): homes held by several co-owners, each
 * for a share (OwnershipShare), managed by an organisation - whose books
 * carry it, every line marked ownership=<id> (Ledger\OwnershipBooks). The
 * shares in force on a date add up to one exactly (problems() says when
 * they do not); a share sold or inherited ends on a date and another one
 * starts, so a period's statement uses the shares in force.
 * Its screens and postings exist when the site runs the `ownership`
 * activity (estate.activities).
 */
#[ORM\Entity(repositoryClass: \Base\Estate\Repository\OwnershipRepository::class)]
#[ORM\Table(name: 'estate_ownership')]
class Ownership
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    /** Who manages it, and whose books carry it. */
    #[ORM\ManyToOne(targetEntity: Organisation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Organisation $organisation;

    #[ORM\Column(length: 160)]
    private string $name = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** @var Collection<int, Unit> */
    #[ORM\ManyToMany(targetEntity: Unit::class)]
    #[ORM\JoinTable(name: 'estate_ownership_unit')]
    private Collection $units;

    /** @var Collection<int, OwnershipShare> */
    #[ORM\OneToMany(targetEntity: OwnershipShare::class, mappedBy: 'ownership', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['startsOn' => 'ASC', 'id' => 'ASC'])]
    private Collection $shares;

    /** The cloud folder shared with its co-owners (omnibase/drive), once made. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $folder = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Organisation $organisation, string $name = '')
    {
        $this->organisation = $organisation;
        $this->name = $name;
        $this->units = new ArrayCollection();
        $this->shares = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string { return $this->name; }
    public function getId(): ?int { return $this->id; }
    public function getOrganisation(): Organisation { return $this->organisation; }
    public function setOrganisation(Organisation $organisation): self { $this->organisation = $organisation; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(?string $name): self { $this->name = trim((string) $name); return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = '' !== trim((string) $description) ? trim((string) $description) : null; return $this; }
    public function getFolder(): ?string { return $this->folder; }
    public function setFolder(?string $folder): self { $this->folder = $folder; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** @return Collection<int, Unit> */
    public function getUnits(): Collection { return $this->units; }
    public function addUnit(Unit $unit): self { if (!$this->units->contains($unit)) { $this->units->add($unit); } return $this; }
    public function removeUnit(Unit $unit): self { $this->units->removeElement($unit); return $this; }

    public function hasUnit(?Unit $unit): bool
    {
        return null !== $unit && $this->units->exists(fn ($k, Unit $u) => $u === $unit || (null !== $u->getId() && $u->getId() === $unit->getId()));
    }

    /** @return Collection<int, OwnershipShare> */
    public function getShares(): Collection { return $this->shares; }

    public function addShare(OwnershipShare $share): self
    {
        if (!$this->shares->contains($share)) {
            $this->shares->add($share);
            $share->setOwnership($this);
        }

        return $this;
    }

    public function removeShare(OwnershipShare $share): self { $this->shares->removeElement($share); return $this; }

    /** @return list<OwnershipShare> the shares in force on $on */
    public function sharesOn(\DateTimeInterface $on): array
    {
        return array_values(array_filter($this->shares->toArray(), fn (OwnershipShare $share) => $share->isInForce($on)));
    }

    /**
     * Each co-owner's fraction on $on, by holder (OwnershipShare::holder()):
     * two shares of one person in force together add up.
     *
     * @return array<string, array{0: int, 1: int}>
     */
    public function fractionsOn(\DateTimeInterface $on): array
    {
        $fractions = [];
        foreach ($this->sharesOn($on) as $share) {
            $fractions[$share->holder()] = Shares::sum([$fractions[$share->holder()] ?? [0, 1], $share->fraction()]);
        }

        return $fractions;
    }

    /**
     * An amount divided by the shares in force on $on, to the cent (Shares::allocate()).
     *
     * @return array<string, int> holder => minor units
     *
     * @throws \InvalidArgumentException the shares on $on do not add up to one
     */
    public function allocate(int $amount, \DateTimeInterface $on): array
    {
        return Shares::allocate($amount, $this->fractionsOn($on));
    }

    /** @return OwnershipShare|null the share that names $holder on $on, else its latest */
    public function shareOf(string $holder, ?\DateTimeInterface $on = null): ?OwnershipShare
    {
        $found = null;
        foreach ($this->shares as $share) {
            if ($share->holder() !== $holder) {
                continue;
            }
            if (null !== $on && $share->isInForce($on)) {
                return $share;
            }
            $found = $share;
        }

        return $found;
    }

    /**
     * The co-owners, each once - by holder, their latest share naming them.
     *
     * @return array<string, OwnershipShare>
     */
    public function holders(): array
    {
        $holders = [];
        foreach ($this->shares as $share) {
            $holders[$share->holder()] = $share;
        }

        return $holders;
    }

    /**
     * Where the shares do not add up to one: on each date they change (a
     * share starting, one ending), the sum of those in force. Empty: the
     * shares are right on every date.
     *
     * @return list<array{on: \DateTimeImmutable, sum: string}>
     */
    public function problems(): array
    {
        if ($this->shares->isEmpty()) {
            return [['on' => new \DateTimeImmutable('today'), 'sum' => '0']];
        }
        $dates = [];
        foreach ($this->shares as $share) {
            $dates[$share->getStartsOn()->format('Y-m-d')] = $share->getStartsOn();
            if (null !== $share->getEndsOn()) {
                $dates[$share->getEndsOn()->format('Y-m-d')] = $share->getEndsOn();
            }
        }
        ksort($dates);
        $last = array_key_last($dates);
        $problems = [];
        foreach ($dates as $key => $on) {
            $inForce = $this->sharesOn($on);
            // The day the last shares end, nothing need be in force any more: the ownership is over.
            if ([] === $inForce && $key === $last) {
                continue;
            }
            [$n, $d] = Shares::sum(array_map(fn (OwnershipShare $share) => $share->fraction(), $inForce));
            if ([1, 1] !== [$n, $d]) {
                $problems[] = ['on' => \DateTimeImmutable::createFromInterface($on), 'sum' => Shares::format($n, $d)];
            }
        }

        return $problems;
    }

    /** For the back office's list: each co-owner's share today, and whether they add up. */
    public function getSharesSummary(): string
    {
        $today = new \DateTimeImmutable('today');
        $parts = array_map(fn (OwnershipShare $share) => $share->getName().' '.$share->getFractionLabel(), $this->sharesOn($today));
        $problems = $this->problems();

        return ([] === $parts ? '—' : implode(' · ', $parts)).([] === $problems ? ' ✓' : sprintf(' — ne font pas 1 (%s au %s)', $problems[0]['sum'], $problems[0]['on']->format('d/m/Y')));
    }

    public function isBalanced(): bool
    {
        return [] === $this->problems();
    }
}
