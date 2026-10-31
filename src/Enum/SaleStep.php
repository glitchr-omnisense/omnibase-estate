<?php

namespace Base\Estate\Enum;

/**
 * The steps of a sale in France, in their order, once an offer is
 * accepted: the compromis or promesse de vente signed (its notary, the
 * deposit), the withdrawal period over (10 days, art. L271-1 CCH, the
 * loi SRU's), the conditions lifted (the loan obtained by its date), the
 * acte authentique signed at the notary's (the sale concluded: the
 * agency's fee is due), the file closed. Or cancelled, with its reason,
 * at any step before the acte.
 */
enum SaleStep: string
{
    case ACCEPTED = 'accepted';
    case COMPROMISE = 'compromise';
    case WITHDRAWAL = 'withdrawal';
    case CONDITIONS = 'conditions';
    case DEED = 'deed';
    case CLOSED = 'closed';
    case CANCELLED = 'cancelled';

    /** The steps a sale goes through, in order (not CANCELLED, which leaves the way). */
    public const PATH = [self::ACCEPTED, self::COMPROMISE, self::WITHDRAWAL, self::CONDITIONS, self::DEED, self::CLOSED];

    public function label(): string
    {
        return match ($this) {
            self::ACCEPTED => 'Offre acceptée',
            self::COMPROMISE => 'Compromis signé',
            self::WITHDRAWAL => 'Délai de rétractation purgé',
            self::CONDITIONS => 'Conditions suspensives levées',
            self::DEED => 'Acte authentique signé',
            self::CLOSED => 'Dossier clos',
            self::CANCELLED => 'Vente annulée',
        };
    }

    /** Its place on the way (0 for an accepted offer), -1 for a cancelled sale. */
    public function rank(): int
    {
        $rank = array_search($this, self::PATH, true);

        return false === $rank ? -1 : $rank;
    }

    /** The step after this one; null at the end or for a cancelled sale. */
    public function next(): ?self
    {
        return self::PATH[$this->rank() + 1] ?? null;
    }

    /** A sale still going: neither closed nor cancelled. */
    public function isLive(): bool
    {
        return self::CLOSED !== $this && self::CANCELLED !== $this;
    }

    /** The sale is concluded (the acte signed): it can no longer be cancelled, its fee is due. */
    public function isConcluded(): bool
    {
        return $this->rank() >= self::DEED->rank();
    }
}
