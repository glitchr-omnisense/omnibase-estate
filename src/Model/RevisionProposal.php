<?php

namespace Base\Estate\Model;

use Base\Estate\Entity\Lease;

/**
 * A lease's next rent revision as estate proposes it (Service\RentRevisions::propose()):
 * the anniversary, the reference quarter and value, the new quarter and - once
 * published and read - its value and the rent it gives. A proposal is never
 * applied by itself: a person confirms it on the revision screen.
 */
final readonly class RevisionProposal
{
    /** The new value is known: the rent can be revised. */
    public const READY = 'ready';
    /** The new quarter is not published yet: the revision waits. */
    public const WAITING = 'waiting';
    /** The source did not answer: the value is typed by hand. */
    public const UNAVAILABLE = 'unavailable';
    /** No source installed: the value is typed by hand. */
    public const MANUAL = 'manual';
    /** The lease has no reference index: nothing to propose. */
    public const NO_REFERENCE = 'no_reference';
    /** The lease has no revision clause. */
    public const NONE = 'none';

    /** A commercial lease's triennial revision (Code de commerce, art. L145-38): the team types the quarter, estate gives the ceiling. */
    public const NOTE_TRIENNIAL = 'triennial';
    /** A change of several years across the ILC's capped quarters (loi n° 2022-1158, art. 14): computed by the team. */
    public const NOTE_CAP_SPAN = 'cap_span';

    public function __construct(
        public Lease $lease,
        public string $status,
        /** The anniversary the revision is due on. */
        public \DateTimeImmutable $on,
        public string $index = 'IRL',
        /** The quarter and value the change is measured from: the lease's, or - years not claimed - the year before the new quarter's. */
        public ?string $fromQuarter = null,
        public ?string $fromValue = null,
        public ?string $quarter = null,
        public ?IndexValue $value = null,
        /** The rent (cents, charges aside) at the new value; null until it is known. */
        public ?int $rent = null,
        /** Years whose revision was never claimed within a year: lost (loi du 6 juillet 1989, art. 17-1). */
        public int $waived = 0,
        /** The cap applied (0.035), when the law capped this change and it reached it. */
        public ?float $capped = null,
        /** Why it is left to the team, beyond the status (NOTE_*). */
        public ?string $note = null,
    ) {
    }

    public function isReady(): bool
    {
        return self::READY === $this->status;
    }
}
