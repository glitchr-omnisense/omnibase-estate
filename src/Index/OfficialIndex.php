<?php

namespace Base\Estate\Index;

use Base\Estate\Exception\IndexUnavailableException;
use Base\Estate\Model\IndexValue;
use Omnistate\Model\TimePeriod;
use Omnistate\Omnistate;
use Psr\Log\LoggerInterface;

/**
 * The official indices a lease is revised on, read through
 * omnisense/omnistate's statistics (omnistate/insee): the IRL, and the ILC
 * and ILAT of commercial and professional leases. Only the published value
 * comes from here; the reference quarter, the anniversary, the caps and the
 * rounding stay estate's (Service\RentRevisions). Answers are kept in
 * omnistate's cache pool (omnistate.cache, a day by default), within INSEE's
 * 30 calls a minute.
 *
 * Registered only when omnistate's statistics are installed (this folder is
 * left out otherwise); nothing here implements an omnistate interface.
 */
class OfficialIndex
{
    /** The names estate knows, as INSEE's BDM names them (omnistate/insee's Insee::SERIES). */
    public const INDICES = ['IRL', 'ILC', 'ILAT'];

    public function __construct(
        private readonly Omnistate $omnistate,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * $index's value for $quarter (« T2 2026 »): its value null while it is
     * not published.
     *
     * @throws IndexUnavailableException the source did not answer, or no source reads it
     */
    public function value(string $index, string $quarter): IndexValue
    {
        $index = strtoupper($index);
        if (!\in_array($index, self::INDICES, true) || 1 !== preg_match('/^T([1-4])\s*(\d{4})$/', strtoupper(trim($quarter)), $m)) {
            throw new \InvalidArgumentException(sprintf('No index "%s" for "%s".', $index, $quarter));
        }
        $period = TimePeriod::quarter((int) $m[2], (int) $m[1]);
        $quarter = sprintf('T%d %d', $period->number, $period->year);
        try {
            $series = $this->omnistate->series('insee:'.$index, $period, $period);
        } catch (\Throwable $e) {
            $this->logger?->warning('The {index} of {quarter} could not be read: {message}', ['index' => $index, 'quarter' => $quarter, 'message' => $e->getMessage()]);

            throw new IndexUnavailableException(sprintf('The %s of %s could not be read: %s', $index, $quarter, $e->getMessage()), 0, $e);
        }
        if (null === $series) {
            throw new IndexUnavailableException(sprintf('INSEE does not know the series %s.', $index));
        }
        $observation = $series->observation($period);
        $value = null === $observation?->value ? null : number_format($observation->value, 2, '.', '');

        return new IndexValue($index, $quarter, $value, '' !== $series->credit ? $series->credit : null, $series->licence, $series->url, $observation?->published, (bool) $observation?->isProvisional());
    }
}
