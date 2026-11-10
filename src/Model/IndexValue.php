<?php

namespace Base\Estate\Model;

/**
 * An official index's value for a quarter, as its source published it: the
 * IRL of a quarter (INSEE), read through omnisense/omnistate
 * (Index\OfficialIndex). $value is null while the quarter is not published:
 * the revision waits. $credit and $licence are the source's, shown beside the
 * figure as its terms ask.
 */
final readonly class IndexValue
{
    public function __construct(
        /** IRL, ILC, ILAT. */
        public string $index,
        /** The quarter as estate writes it: « T2 2026 ». */
        public string $quarter,
        /** The value, two decimals (« 148.37 »); null: not published yet. */
        public ?string $value,
        public ?string $credit = null,
        public ?string $licence = null,
        public ?string $url = null,
        /** When it was published (INSEE: its date in the Journal officiel). */
        public ?\DateTimeImmutable $publishedOn = null,
        public bool $provisional = false,
    ) {
    }

    public function isPublished(): bool
    {
        return null !== $this->value;
    }
}
