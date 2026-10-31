<?php

namespace Base\Estate\Entity;

use Base\Estate\Enum\StatementFrequency;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A management mandate (mandat de gestion): an owner (Mandator) entrusts
 * homes to the agency (the organisation, whose books carry it) from a day
 * until a day, for a fee - a rate of the rents collected, before tax, its
 * VAT from the book's codes (V20 by default) -, with a statement and a
 * payout every month (or quarter, or year) and, if agreed, a reserve kept
 * on the owner's account for the expenses to come.
 *
 * A home under a mandate is its owner's: its rents go to the owner's
 * account, not to the agency's income (Ledger\ManagementBooks). The home,
 * its leases and its rent calls stay estate's own; the mandate only names
 * its homes (estate_mandate_unit), so a home's mandates over time are kept
 * and nothing changes on the home itself.
 */
#[ORM\Entity(repositoryClass: \Base\Estate\Repository\MandateRepository::class)]
#[ORM\Table(name: 'estate_mandate')]
class Mandate
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    /** The agency: who manages, and whose books carry the owner's account. */
    #[ORM\ManyToOne(targetEntity: Organisation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Organisation $organisation;

    #[ORM\ManyToOne(targetEntity: Mandator::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Mandator $mandator;

    /** Its number in the register of mandates (loi Hoguet): MG-2026-0001, given when saved. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $number = null;

    /** @var Collection<int, Unit> */
    #[ORM\ManyToMany(targetEntity: Unit::class)]
    #[ORM\JoinTable(name: 'estate_mandate_unit')]
    private Collection $units;

    /** The fee, in basis points of the rents collected, before tax: 700 is 7 %. */
    #[ORM\Column]
    private int $feeRate = 700;

    /** The fee's VAT code in the agency's book (V20): VAT is added when the book is liable. */
    #[ORM\Column(length: 12, nullable: true)]
    private ?string $taxCode = 'V20';

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $startsOn;

    /** Its last day; null: open-ended. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $endsOn = null;

    #[ORM\Column(length: 16, enumType: StatementFrequency::class)]
    private StatementFrequency $frequency = StatementFrequency::MONTHLY;

    /** Kept on the owner's account at each payout, in minor units, for the expenses to come. */
    #[ORM\Column]
    private int $reserve = 0;

    /** The latest period whose statement was sent ("2026-10"), and when. */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $statementSentFor = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $statementSentAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(?Organisation $organisation = null, ?Mandator $mandator = null, ?\DateTimeImmutable $startsOn = null)
    {
        if ($organisation) {
            $this->organisation = $organisation;
        }
        if ($mandator) {
            $this->mandator = $mandator;
        }
        $this->units = new ArrayCollection();
        $this->startsOn = ($startsOn ?? new \DateTimeImmutable('today'))->setTime(0, 0);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string { return trim(($this->number ?? 'Mandat').' — '.(isset($this->mandator) ? $this->mandator->getName() : ''), ' —'); }
    public function getId(): ?int { return $this->id; }
    public function getOrganisation(): Organisation { return $this->organisation; }
    public function setOrganisation(Organisation $organisation): self { $this->organisation = $organisation; return $this; }
    public function getMandator(): Mandator { return $this->mandator; }
    public function setMandator(Mandator $mandator): self { $this->mandator = $mandator; return $this; }
    public function getNumber(): ?string { return $this->number; }
    public function setNumber(?string $number): self { $this->number = '' !== trim((string) $number) ? trim((string) $number) : null; return $this; }
    public function getFeeRate(): int { return $this->feeRate; }
    public function setFeeRate(?int $feeRate): self { $this->feeRate = max(0, min(10000, (int) $feeRate)); return $this; }
    public function getTaxCode(): ?string { return $this->taxCode; }
    public function setTaxCode(?string $taxCode): self { $taxCode = strtoupper(trim((string) $taxCode)); $this->taxCode = '' === $taxCode ? null : $taxCode; return $this; }
    public function getStartsOn(): \DateTimeImmutable { return $this->startsOn; }
    public function setStartsOn(?\DateTimeImmutable $startsOn): self { $this->startsOn = ($startsOn ?? new \DateTimeImmutable('today'))->setTime(0, 0); return $this; }
    public function getEndsOn(): ?\DateTimeImmutable { return $this->endsOn; }
    public function setEndsOn(?\DateTimeImmutable $endsOn): self { $this->endsOn = $endsOn?->setTime(0, 0); return $this; }
    public function getFrequency(): StatementFrequency { return $this->frequency; }
    public function setFrequency(StatementFrequency $frequency): self { $this->frequency = $frequency; return $this; }
    public function getReserve(): int { return $this->reserve; }
    public function setReserve(?int $reserve): self { $this->reserve = max(0, (int) $reserve); return $this; }
    public function getStatementSentFor(): ?string { return $this->statementSentFor; }
    public function getStatementSentAt(): ?\DateTimeImmutable { return $this->statementSentAt; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self { $this->notes = '' !== trim((string) $notes) ? trim((string) $notes) : null; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** A period's statement sent: the latest one is kept, an older one sent again leaves it. */
    public function markStatementSent(string $period, ?\DateTimeImmutable $at = null): self
    {
        if (null === $this->statementSentFor || strcmp(self::sortable($period), self::sortable($this->statementSentFor)) >= 0) {
            $this->statementSentFor = $period;
        }
        $this->statementSentAt = $at ?? new \DateTimeImmutable();

        return $this;
    }

    /** Whether the statement of $period has gone out (that period's or a later one's). */
    public function isStatementSent(string $period): bool
    {
        return null !== $this->statementSentFor && strcmp(self::sortable($this->statementSentFor), self::sortable($period)) >= 0;
    }

    /** @return Collection<int, Unit> */
    public function getUnits(): Collection { return $this->units; }
    public function addUnit(Unit $unit): self { if (!$this->units->contains($unit)) { $this->units->add($unit); } return $this; }
    public function removeUnit(Unit $unit): self { $this->units->removeElement($unit); return $this; }

    public function hasUnit(?Unit $unit): bool
    {
        return null !== $unit && $this->units->exists(fn ($k, Unit $u) => $u === $unit || (null !== $u->getId() && $u->getId() === $unit->getId()));
    }

    /** Whether the mandate runs on $day: from its first day to its last, both included. */
    public function covers(\DateTimeInterface $day): bool
    {
        $day = $day->format('Y-m-d');

        return $this->startsOn->format('Y-m-d') <= $day && (null === $this->endsOn || $this->endsOn->format('Y-m-d') >= $day);
    }

    /** Whether the mandate runs on one day at least of a period. */
    public function overlaps(\DateTimeInterface $from, \DateTimeInterface $to): bool
    {
        return $this->startsOn->format('Y-m-d') <= $to->format('Y-m-d') && (null === $this->endsOn || $this->endsOn->format('Y-m-d') >= $from->format('Y-m-d'));
    }

    /** The fee in percent, as the back office types it (7, 6.5): kept in basis points. */
    public function getFeePercent(): float { return $this->feeRate / 100; }
    public function setFeePercent(int|float|string|null $percent): self { return $this->setFeeRate((int) round(100 * (float) str_replace(',', '.', (string) $percent))); }

    /** "7 %", "6,5 %": the fee as typed. */
    public function getFeeLabel(): string
    {
        return rtrim(rtrim(number_format($this->feeRate / 100, 2, ',', ' '), '0'), ',').' %';
    }

    /** For the back office's list: its homes. */
    public function getUnitsSummary(): string
    {
        return implode(', ', array_map(fn (Unit $unit) => $unit->getName(), $this->units->toArray())) ?: '—';
    }

    /** "2026-10" < "2026-Q4" < "2026" do not sort as text: the period's last day does. */
    private static function sortable(string $period): string
    {
        try {
            return \Base\Estate\Model\Period::parse($period)[1]->format('Y-m-d');
        } catch (\InvalidArgumentException) {
            return $period;
        }
    }
}
