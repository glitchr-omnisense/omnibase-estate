<?php

namespace Base\Estate\Model;

use Base\Estate\Entity\Application;
use Base\Estate\Entity\Unit;
use Base\Estate\Enum\Guarantee;
use Base\Estate\Enum\Occupation;
use Symfony\Component\Validator\Constraints as Assert;

/** What the application form asks (ApplicationType), before it becomes an Application. */
final class ApplicationRequest
{
    #[Assert\NotBlank, Assert\Length(max: 80)]
    public ?string $firstName = null;

    #[Assert\NotBlank, Assert\Length(max: 80)]
    public ?string $lastName = null;

    #[Assert\NotBlank, Assert\Email]
    public ?string $email = null;

    #[Assert\Length(max: 40)]
    public ?string $phone = null;

    #[Assert\NotBlank, Assert\Range(min: 1, max: 12)]
    public ?int $occupants = 1;

    public ?\DateTimeImmutable $moveInOn = null;

    #[Assert\NotNull]
    public ?Occupation $occupation = Occupation::PERMANENT;

    /** Net monthly income of the household, in cents. */
    #[Assert\NotBlank, Assert\PositiveOrZero]
    public ?int $income = null;

    #[Assert\NotNull]
    public ?Guarantee $guarantee = Guarantee::NONE;

    #[Assert\Url(requireTld: true), Assert\Length(max: 255)]
    public ?string $dossierUrl = null;

    #[Assert\Length(max: 3000)]
    public ?string $message = null;

    public function toApplication(Unit $unit): Application
    {
        return (new Application($unit))
            ->setFirstName($this->firstName)->setLastName($this->lastName)->setEmail($this->email)->setPhone($this->phone)
            ->setOccupants($this->occupants)->setMoveInOn($this->moveInOn)->setOccupation($this->occupation ?? Occupation::OTHER)
            ->setIncome($this->income)->setGuarantee($this->guarantee ?? Guarantee::NONE)->setDossierUrl($this->dossierUrl)->setMessage($this->message);
    }
}
