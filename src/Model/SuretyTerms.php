<?php

namespace Base\Estate\Model;

use Base\Estate\Entity\LeaseParty;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A guarantor's commitment as the team sets it before the deed is sent (Form\SuretyTermsType):
 * the form's data, copied to the LeaseParty - omnibase's form factory takes no entity as data.
 */
final class SuretyTerms
{
    /** The limit in principal and accessories, in cents (code civil, art. 2297). */
    #[Assert\NotBlank(message: '@estate.surety.limit_needed')]
    #[Assert\Positive]
    public ?int $limit = null;

    public ?\DateTimeImmutable $until = null;

    public bool $joint = true;

    #[Assert\Length(max: 255)]
    public ?string $address = null;

    public static function of(LeaseParty $guarantor): self
    {
        $terms = new self();
        $terms->limit = $guarantor->getSuretyLimit();
        $terms->until = $guarantor->getSuretyUntil();
        $terms->joint = $guarantor->isSuretyJoint();
        $terms->address = $guarantor->getAddress();

        return $terms;
    }

    public function applyTo(LeaseParty $guarantor): void
    {
        $guarantor->setSuretyLimit($this->limit)->setSuretyUntil($this->until)->setSuretyJoint($this->joint)->setAddress($this->address);
    }
}
