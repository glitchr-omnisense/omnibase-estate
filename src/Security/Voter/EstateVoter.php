<?php

namespace Base\Estate\Security\Voter;

use Base\Entity\User;
use Base\Estate\Entity\Document;
use Base\Estate\Entity\Lease;
use Base\Estate\Entity\Mandate;
use Base\Estate\Entity\MandatePayout;
use Base\Estate\Entity\Mandator;
use Base\Estate\Entity\Organisation;
use Base\Estate\Entity\Ownership;
use Base\Estate\Entity\OwnershipCall;
use Base\Estate\Entity\OwnershipInvoice;
use Base\Estate\Entity\RentCall;
use Base\Estate\Entity\Unit;
use Base\Estate\Entity\WorkOrder;
use Base\Estate\Service\Spaces;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * What each person may open, from what they are to the organisation
 * (Spaces): its management everything of it; an associate its figures and
 * feed; a tenant their lease, its rents and the problems they reported; a
 * contractor the work orders given to them; a co-owner their shared
 * ownership, its invoices and their own calls for funds; an owner under
 * management their mandates, their statements and payouts - nothing else.
 */
final class EstateVoter extends Voter
{
    public const MANAGE = 'ESTATE_MANAGE';
    public const ASSOCIATE = 'ESTATE_ASSOCIATE';
    public const LEASE_VIEW = 'ESTATE_LEASE_VIEW';
    public const RENT_VIEW = 'ESTATE_RENT_VIEW';
    public const UNIT_REPORT = 'ESTATE_UNIT_REPORT';
    public const WORK_VIEW = 'ESTATE_WORK_VIEW';
    public const WORK_DO = 'ESTATE_WORK_DO';
    public const DOCUMENT_VIEW = 'ESTATE_DOCUMENT_VIEW';
    public const OWNERSHIP_VIEW = 'ESTATE_OWNERSHIP_VIEW';
    public const MANDATE_VIEW = 'ESTATE_MANDATE_VIEW';

    public function __construct(private readonly Spaces $spaces)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::MANAGE, self::ASSOCIATE => $subject instanceof Organisation,
            self::LEASE_VIEW => $subject instanceof Lease,
            self::RENT_VIEW => $subject instanceof RentCall,
            self::UNIT_REPORT => $subject instanceof Unit,
            self::WORK_VIEW, self::WORK_DO => $subject instanceof WorkOrder,
            self::DOCUMENT_VIEW => $subject instanceof Document,
            self::OWNERSHIP_VIEW => $subject instanceof Ownership || $subject instanceof OwnershipCall || $subject instanceof OwnershipInvoice,
            self::MANDATE_VIEW => $subject instanceof Mandator || $subject instanceof Mandate || $subject instanceof MandatePayout,
            default => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }
        // An owner sees their own mandates, statements and payouts; the agency's management all of them.
        if (self::MANDATE_VIEW === $attribute) {
            $mandator = $subject instanceof Mandator ? $subject : ($subject instanceof Mandate ? $subject->getMandator() : $subject->getMandate()->getMandator());
            $organisation = $subject instanceof Mandate ? $subject->getOrganisation() : ($subject instanceof MandatePayout ? $subject->getMandate()->getOrganisation() : null);

            return (null !== $organisation ? $this->spaces->manages($user, $organisation) : [] !== $this->spaces->managed($user))
                || $this->spaces->isMandator($user, $mandator);
        }

        $organisation = match (true) {
            $subject instanceof Organisation => $subject,
            $subject instanceof Lease, $subject instanceof WorkOrder, $subject instanceof Unit, $subject instanceof Document => $subject->getOrganisation(),
            $subject instanceof RentCall => $subject->getLease()->getOrganisation(),
            $subject instanceof Ownership => $subject->getOrganisation(),
            $subject instanceof OwnershipCall, $subject instanceof OwnershipInvoice => $subject->getOwnership()->getOrganisation(),
        };
        if ($this->spaces->manages($user, $organisation)) {
            return true;
        }

        return match ($attribute) {
            self::MANAGE => false,
            self::ASSOCIATE => $this->spaces->isAssociateOf($user, $subject),
            self::LEASE_VIEW => $this->spaces->isPartyOf($user, $subject),
            self::RENT_VIEW => $this->spaces->isPartyOf($user, $subject->getLease()),
            self::UNIT_REPORT => null !== ($lease = $subject->getCurrentLease()) && $this->spaces->isPartyOf($user, $lease),
            self::WORK_VIEW => $this->isContractorOf($user, $subject)
                || (null !== ($lease = $subject->getUnit()->getCurrentLease()) && $this->spaces->isPartyOf($user, $lease))
                || $this->spaces->isAssociateOf($user, $organisation),
            self::WORK_DO => $this->isContractorOf($user, $subject),
            self::DOCUMENT_VIEW => $this->canSeeDocument($user, $subject, $organisation),
            // A co-owner sees their ownership and its invoices, and of the calls only their own.
            self::OWNERSHIP_VIEW => $subject instanceof OwnershipCall
                ? \in_array($subject->getHolder(), $this->spaces->holdersIn($user, $subject->getOwnership()), true)
                : $this->spaces->isCoOwnerOf($user, $subject instanceof Ownership ? $subject : $subject->getOwnership()),
        };
    }

    /**
     * The associates see the company's records; a work order's contractor its files; the
     * tenants what is shared with them - their lease's documents, and their home's own
     * (diagnostics, the building's rules) while they live there.
     */
    private function canSeeDocument(User $user, Document $document, Organisation $organisation): bool
    {
        if ($this->spaces->isAssociateOf($user, $organisation)) {
            return true;
        }
        if ($document->getWorkOrder()) {
            return $this->isContractorOf($user, $document->getWorkOrder())
                || ($document->isTenantVisible() && null !== ($lease = $document->getWorkOrder()->getUnit()->getCurrentLease()) && $this->spaces->isPartyOf($user, $lease));
        }
        if (!$document->isTenantVisible()) {
            return false;
        }
        if ($document->getLease()) {
            return $this->spaces->isPartyOf($user, $document->getLease());
        }

        return null !== ($lease = $document->getUnit()?->getCurrentLease()) && $this->spaces->isPartyOf($user, $lease);
    }

    private function isContractorOf(User $user, WorkOrder $order): bool
    {
        return null !== $order->getContractor()
            && \in_array($order->getContractor()->getId(), array_map(fn ($c) => $c->getId(), $this->spaces->contractorships($user)), true);
    }
}
