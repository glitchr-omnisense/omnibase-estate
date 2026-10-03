<?php

namespace Base\Estate\Entity;

use Base\Estate\Enum\UnitKind;
use Base\Estate\Repository\UnitRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A home the organisation lets: a house, a flat, a studio, a parking space,
 * premises. Published, it is on the public site with its photos, what it
 * costs and when it is free; its façade colour paints it until a photo is in.
 */
#[ORM\Entity(repositoryClass: UnitRepository::class)]
#[ORM\Table(name: 'estate_unit')]
class Unit
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Organisation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Organisation $organisation;

    #[ORM\ManyToOne(targetEntity: Building::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Building $building = null;

    #[ORM\Column(length: 16, enumType: UnitKind::class)]
    private UnitKind $kind = UnitKind::APARTMENT;

    #[ORM\Column(length: 160)]
    private string $name = '';

    #[ORM\Column(length: 160, unique: true)]
    private string $slug = '';

    /** Its own address, when it is not in a building. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(length: 12, nullable: true)]
    private ?string $postcode = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $city = null;

    /** The neighbourhood, what the public site shows instead of the address. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $district = null;

    /** Its door: "2e étage gauche", "Lot 12". */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $door = null;

    #[ORM\Column(type: 'decimal', precision: 7, scale: 2, nullable: true)]
    private ?string $surface = null;

    #[ORM\Column(nullable: true)]
    private ?int $rooms = null;

    #[ORM\Column(nullable: true)]
    private ?int $bedrooms = null;

    #[ORM\Column(nullable: true)]
    private ?int $floor = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $heating = null;

    /** DPE class, A to G. */
    #[ORM\Column(length: 1, nullable: true)]
    private ?string $energyClass = null;

    /** kWh/m²/year. */
    #[ORM\Column(nullable: true)]
    private ?int $energyValue = null;

    #[ORM\Column(length: 1, nullable: true)]
    private ?string $climateClass = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** @var list<string> what it has: "Balcon", "Cave", "Ascenseur"... */
    #[ORM\Column(type: 'json')]
    private array $features = [];

    /** The rent asked, without charges, in cents. */
    #[ORM\Column]
    private int $rent = 0;

    /** The monthly provision for charges, in cents. */
    #[ORM\Column]
    private int $charges = 0;

    #[ORM\Column]
    private int $deposit = 0;

    #[ORM\Column]
    private bool $furnished = false;

    #[ORM\Column]
    private bool $published = false;

    /** When it is free; null while let. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $availableFrom = null;

    /** The façade it is painted in until a photo is in: ochre, rose, sage, sky, brick, mustard. */
    #[ORM\Column(length: 16)]
    private string $facade = 'ochre';

    #[ORM\Column]
    private int $position = 0;

    /** @var Collection<int, UnitPhoto> */
    #[ORM\OneToMany(targetEntity: UnitPhoto::class, mappedBy: 'unit', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $photos;

    /** @var Collection<int, Lease> */
    #[ORM\OneToMany(targetEntity: Lease::class, mappedBy: 'unit')]
    #[ORM\OrderBy(['startOn' => 'DESC'])]
    private Collection $leases;

    public function __construct(Organisation $organisation, string $name = '', string $slug = '')
    {
        $this->organisation = $organisation;
        $this->name = $name;
        $this->slug = $slug;
        $this->photos = new ArrayCollection();
        $this->leases = new ArrayCollection();
    }

    public function __toString(): string { return $this->name; }
    public function getId(): ?int { return $this->id; }
    public function getOrganisation(): Organisation { return $this->organisation; }
    public function setOrganisation(Organisation $organisation): self { $this->organisation = $organisation; return $this; }
    public function getBuilding(): ?Building { return $this->building; }
    public function setBuilding(?Building $building): self { $this->building = $building; return $this; }
    public function getKind(): UnitKind { return $this->kind; }
    public function setKind(UnitKind $kind): self { $this->kind = $kind; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): self { $this->slug = $slug; return $this; }
    public function getAddress(): ?string { return $this->address ?? $this->building?->getAddress(); }
    public function setAddress(?string $address): self { $this->address = $address; return $this; }
    public function getPostcode(): ?string { return $this->postcode ?? $this->building?->getPostcode(); }
    public function setPostcode(?string $postcode): self { $this->postcode = $postcode; return $this; }
    public function getCity(): ?string { return $this->city ?? $this->building?->getCity(); }
    public function setCity(?string $city): self { $this->city = $city; return $this; }
    public function getDistrict(): ?string { return $this->district; }
    public function setDistrict(?string $district): self { $this->district = $district; return $this; }
    public function getDoor(): ?string { return $this->door; }
    public function setDoor(?string $door): self { $this->door = $door; return $this; }
    public function getSurface(): ?float { return null === $this->surface ? null : (float) $this->surface; }
    public function setSurface(float|string|null $surface): self { $this->surface = null === $surface || '' === $surface ? null : (string) $surface; return $this; }
    public function getRooms(): ?int { return $this->rooms; }
    public function setRooms(?int $rooms): self { $this->rooms = $rooms; return $this; }
    public function getBedrooms(): ?int { return $this->bedrooms; }
    public function setBedrooms(?int $bedrooms): self { $this->bedrooms = $bedrooms; return $this; }
    public function getFloor(): ?int { return $this->floor; }
    public function setFloor(?int $floor): self { $this->floor = $floor; return $this; }
    public function getHeating(): ?string { return $this->heating; }
    public function setHeating(?string $heating): self { $this->heating = $heating; return $this; }
    public function getEnergyClass(): ?string { return $this->energyClass; }
    public function setEnergyClass(?string $energyClass): self { $this->energyClass = $energyClass ? strtoupper($energyClass) : null; return $this; }
    public function getEnergyValue(): ?int { return $this->energyValue; }
    public function setEnergyValue(?int $energyValue): self { $this->energyValue = $energyValue; return $this; }
    public function getClimateClass(): ?string { return $this->climateClass; }
    public function setClimateClass(?string $climateClass): self { $this->climateClass = $climateClass ? strtoupper($climateClass) : null; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description; return $this; }
    /** @return list<string> */
    public function getFeatures(): array { return $this->features; }
    /** @param list<string>|string|null $features */
    public function setFeatures(array|string|null $features): self
    {
        $features = \is_string($features) ? preg_split('/\s*[,;\n]\s*/', $features) : ($features ?? []);
        $this->features = array_values(array_filter(array_map('trim', $features)));

        return $this;
    }
    public function getRent(): int { return $this->rent; }
    public function setRent(?int $rent): self { $this->rent = (int) $rent; return $this; }
    public function getCharges(): int { return $this->charges; }
    public function setCharges(?int $charges): self { $this->charges = (int) $charges; return $this; }
    public function getDeposit(): int { return $this->deposit; }
    public function setDeposit(?int $deposit): self { $this->deposit = (int) $deposit; return $this; }
    public function isFurnished(): bool { return $this->furnished; }
    public function setFurnished(bool $furnished): self { $this->furnished = $furnished; return $this; }
    public function isPublished(): bool { return $this->published; }
    public function setPublished(bool $published): self { $this->published = $published; return $this; }
    public function getAvailableFrom(): ?\DateTimeImmutable { return $this->availableFrom; }
    public function setAvailableFrom(?\DateTimeImmutable $availableFrom): self { $this->availableFrom = $availableFrom; return $this; }
    public function getFacade(): string { return $this->facade; }
    public function setFacade(?string $facade): self { $this->facade = $facade ?: 'ochre'; return $this; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(?int $position): self { $this->position = (int) $position; return $this; }

    /** @return Collection<int, UnitPhoto> */
    public function getPhotos(): Collection { return $this->photos; }
    public function addPhoto(UnitPhoto $photo): self
    {
        if (!$this->photos->contains($photo)) {
            $this->photos->add($photo);
            $photo->setUnit($this);
        }

        return $this;
    }
    public function removePhoto(UnitPhoto $photo): self { $this->photos->removeElement($photo); return $this; }
    public function getCover(): ?UnitPhoto { return $this->photos->first() ?: null; }

    /** @return Collection<int, Lease> */
    public function getLeases(): Collection { return $this->leases; }

    /** The lease running today, if any. */
    public function getCurrentLease(?\DateTimeImmutable $on = null): ?Lease
    {
        $on ??= new \DateTimeImmutable('today');
        foreach ($this->leases as $lease) {
            if ($lease->isRunningOn($on)) {
                return $lease;
            }
        }

        return null;
    }

    public function isLet(?\DateTimeImmutable $on = null): bool
    {
        return null !== $this->getCurrentLease($on);
    }

    /** Free today, or from a date: what the public site says. */
    public function isAvailable(): bool
    {
        return null !== $this->availableFrom;
    }
}
