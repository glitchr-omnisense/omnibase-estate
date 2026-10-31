<?php

namespace Base\Estate\Entity;

use Base\Estate\Enum\FeeBasis;
use Base\Estate\Enum\FeePayer;
use Base\Estate\Enum\UnitKind;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A sale mandate (mandat de vente): the seller(s) - owners, Mandator, as
 * under management - entrust the agency with selling a property: one of
 * estate's homes, or one described in place when the agency does not
 * manage it (address, kind, surface, rooms). The asking price, simple or
 * exclusive, from a day until a day (loi Hoguet: a mandate has a term),
 * the fee - a percentage of the sale price or a fixed amount, all taxes
 * included as French transaction fees are stated (arrêté du 10 janvier
 * 2017), its VAT drawn from the book's code (V20) when the agency is
 * liable -, who pays it (the seller or the buyer), whether the agency
 * holds the deposit (the notary does, usually). Numbered in the register
 * of mandates when saved: VT-2026-0001.
 *
 * Its screens and postings exist when the site runs the `transaction`
 * activity (estate.activities).
 */
#[ORM\Entity(repositoryClass: \Base\Estate\Repository\SaleMandateRepository::class)]
#[ORM\Table(name: 'estate_sale_mandate')]
class SaleMandate
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    /** The agency: who sells, and whose books carry the fee. */
    #[ORM\ManyToOne(targetEntity: Organisation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Organisation $organisation;

    /** Its number in the register of mandates (loi Hoguet): VT-2026-0001, given when saved. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $number = null;

    /** @var Collection<int, Mandator> */
    #[ORM\ManyToMany(targetEntity: Mandator::class)]
    #[ORM\JoinTable(name: 'estate_sale_mandate_seller')]
    private Collection $sellers;

    /** One of estate's homes; null: the property is described below. */
    #[ORM\ManyToOne(targetEntity: Unit::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Unit $unit = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(length: 12, nullable: true)]
    private ?string $postcode = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 16, nullable: true, enumType: UnitKind::class)]
    private ?UnitKind $kind = null;

    #[ORM\Column(type: 'decimal', precision: 7, scale: 2, nullable: true)]
    private ?string $surface = null;

    #[ORM\Column(nullable: true)]
    private ?int $rooms = null;

    /** The asking price, in minor units. */
    #[ORM\Column]
    private int $price = 0;

    /** Exclusive: the seller sells through this agency alone during the mandate. */
    #[ORM\Column]
    private bool $exclusive = false;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $startsOn;

    /** Its last day, included: a sale mandate has a term (loi Hoguet). */
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $endsOn;

    #[ORM\Column(length: 12, enumType: FeeBasis::class)]
    private FeeBasis $feeBasis = FeeBasis::PERCENT;

    /** The fee in basis points of the sale price, all taxes included: 500 is 5 %. */
    #[ORM\Column]
    private int $feeRate = 500;

    /** The fixed fee, all taxes included, in minor units. */
    #[ORM\Column]
    private int $feeAmount = 0;

    #[ORM\Column(length: 12, enumType: FeePayer::class)]
    private FeePayer $feePayer = FeePayer::SELLER;

    /** The fee's VAT code in the agency's book (V20): the VAT is drawn out of the fee when the book is liable. */
    #[ORM\Column(length: 12, nullable: true)]
    private ?string $taxCode = 'V20';

    /** The agency holds the deposit (séquestre) on its escrow account; the notary does otherwise. */
    #[ORM\Column]
    private bool $depositHeldByAgency = false;

    /** Its cloud folder, Transactions/<number> (omnibase/drive), once made. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $folder = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(?Organisation $organisation = null, ?\DateTimeImmutable $startsOn = null)
    {
        if ($organisation) {
            $this->organisation = $organisation;
        }
        $this->sellers = new ArrayCollection();
        $this->startsOn = ($startsOn ?? new \DateTimeImmutable('today'))->setTime(0, 0);
        $this->endsOn = $this->startsOn->modify('+3 months -1 day');
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string { return trim(($this->number ?? 'Mandat de vente').' — '.$this->getPropertyName(), ' —'); }
    public function getId(): ?int { return $this->id; }
    public function getOrganisation(): Organisation { return $this->organisation; }
    public function setOrganisation(Organisation $organisation): self { $this->organisation = $organisation; return $this; }
    public function getNumber(): ?string { return $this->number; }
    public function setNumber(?string $number): self { $this->number = '' !== trim((string) $number) ? trim((string) $number) : null; return $this; }
    public function getUnit(): ?Unit { return $this->unit; }
    public function setUnit(?Unit $unit): self { $this->unit = $unit; return $this; }
    public function getAddress(): ?string { return $this->address ?? $this->unit?->getAddress(); }
    public function setAddress(?string $address): self { $this->address = '' !== trim((string) $address) ? trim((string) $address) : null; return $this; }
    public function getPostcode(): ?string { return $this->postcode ?? $this->unit?->getPostcode(); }
    public function setPostcode(?string $postcode): self { $this->postcode = '' !== trim((string) $postcode) ? trim((string) $postcode) : null; return $this; }
    public function getCity(): ?string { return $this->city ?? $this->unit?->getCity(); }
    public function setCity(?string $city): self { $this->city = '' !== trim((string) $city) ? trim((string) $city) : null; return $this; }
    public function getKind(): ?UnitKind { return $this->kind ?? $this->unit?->getKind(); }
    public function setKind(?UnitKind $kind): self { $this->kind = $kind; return $this; }
    public function getSurface(): ?float { return null !== $this->surface ? (float) $this->surface : $this->unit?->getSurface(); }
    public function setSurface(float|string|null $surface): self { $this->surface = null === $surface || '' === $surface ? null : (string) $surface; return $this; }
    public function getRooms(): ?int { return $this->rooms ?? $this->unit?->getRooms(); }
    public function setRooms(?int $rooms): self { $this->rooms = $rooms; return $this; }
    public function getPrice(): int { return $this->price; }
    public function setPrice(?int $price): self { $this->price = max(0, (int) $price); return $this; }
    public function isExclusive(): bool { return $this->exclusive; }
    public function setExclusive(?bool $exclusive): self { $this->exclusive = (bool) $exclusive; return $this; }
    public function getStartsOn(): \DateTimeImmutable { return $this->startsOn; }
    public function setStartsOn(?\DateTimeImmutable $startsOn): self { $this->startsOn = ($startsOn ?? new \DateTimeImmutable('today'))->setTime(0, 0); return $this; }
    public function getEndsOn(): \DateTimeImmutable { return $this->endsOn; }
    public function setEndsOn(?\DateTimeImmutable $endsOn): self { $this->endsOn = ($endsOn ?? $this->startsOn->modify('+3 months -1 day'))->setTime(0, 0); return $this; }
    public function getFeeBasis(): FeeBasis { return $this->feeBasis; }
    public function setFeeBasis(FeeBasis $feeBasis): self { $this->feeBasis = $feeBasis; return $this; }
    public function getFeeRate(): int { return $this->feeRate; }
    public function setFeeRate(?int $feeRate): self { $this->feeRate = max(0, min(10000, (int) $feeRate)); return $this; }
    public function getFeeAmount(): int { return $this->feeAmount; }
    public function setFeeAmount(?int $feeAmount): self { $this->feeAmount = max(0, (int) $feeAmount); return $this; }
    public function getFeePayer(): FeePayer { return $this->feePayer; }
    public function setFeePayer(FeePayer $feePayer): self { $this->feePayer = $feePayer; return $this; }
    public function getTaxCode(): ?string { return $this->taxCode; }
    public function setTaxCode(?string $taxCode): self { $taxCode = strtoupper(trim((string) $taxCode)); $this->taxCode = '' === $taxCode ? null : $taxCode; return $this; }
    public function isDepositHeldByAgency(): bool { return $this->depositHeldByAgency; }
    public function setDepositHeldByAgency(?bool $held): self { $this->depositHeldByAgency = (bool) $held; return $this; }
    public function getFolder(): ?string { return $this->folder; }
    public function setFolder(?string $folder): self { $this->folder = $folder; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self { $this->notes = '' !== trim((string) $notes) ? trim((string) $notes) : null; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** @return Collection<int, Mandator> */
    public function getSellers(): Collection { return $this->sellers; }
    public function addSeller(Mandator $seller): self { if (!$this->sellers->contains($seller)) { $this->sellers->add($seller); } return $this; }
    public function removeSeller(Mandator $seller): self { $this->sellers->removeElement($seller); return $this; }

    public function hasSeller(?Mandator $mandator): bool
    {
        return null !== $mandator && $this->sellers->exists(fn ($k, Mandator $m) => $m === $mandator || (null !== $m->getId() && $m->getId() === $mandator->getId()));
    }

    /** "Paul Klein, Anne Klein". */
    public function getSellersSummary(): string
    {
        return implode(', ', array_map(fn (Mandator $seller) => $seller->getName(), $this->sellers->toArray())) ?: '—';
    }

    /** The home's name, or the property described: "Appartement 3 pièces, Strasbourg". */
    public function getPropertyName(): string
    {
        if (null !== $this->unit) {
            return $this->unit->getName();
        }
        $kind = match ($this->kind) {
            UnitKind::HOUSE => 'Maison', UnitKind::APARTMENT => 'Appartement', UnitKind::STUDIO => 'Studio',
            UnitKind::PARKING => 'Parking', UnitKind::COMMERCIAL => 'Local commercial', null => 'Bien',
        };

        return trim($kind.($this->rooms ? ' '.$this->rooms.' pièces' : '').($this->city ? ', '.$this->city : ''));
    }

    public function getFullAddress(): string
    {
        return trim(implode(', ', array_filter([$this->getAddress(), trim($this->getPostcode().' '.$this->getCity())])), ', ');
    }

    /** Whether the mandate runs on $day: from its first day to its last, both included. */
    public function covers(\DateTimeInterface $day): bool
    {
        $day = $day->format('Y-m-d');

        return $this->startsOn->format('Y-m-d') <= $day && $this->endsOn->format('Y-m-d') >= $day;
    }

    /** The fee in percent, as the back office types it (5, 4.5): kept in basis points. */
    public function getFeePercent(): float { return $this->feeRate / 100; }
    public function setFeePercent(int|float|string|null $percent): self { return $this->setFeeRate((int) round(100 * (float) str_replace(',', '.', (string) $percent))); }

    /** "5 % TTC", "8 000,00 € TTC": the fee as the mandate states it. */
    public function getFeeLabel(): string
    {
        return FeeBasis::FIXED === $this->feeBasis
            ? number_format($this->feeAmount / 100, 2, ',', ' ').' € TTC'
            : rtrim(rtrim(number_format($this->feeRate / 100, 2, ',', ' '), '0'), ',').' % TTC';
    }
}
