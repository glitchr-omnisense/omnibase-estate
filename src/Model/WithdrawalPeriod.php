<?php

namespace Base\Estate\Model;

/**
 * The buyer's withdrawal period after a compromis or promesse de vente
 * (délai de rétractation, art. L271-1 of the Code de la construction et de
 * l'habitation, the loi SRU's, 10 days since the loi Macron): it runs from
 * the day after the deed was notified to the buyer (the first presentation
 * of the registered letter, or the hand delivery against a receipt) and
 * ends on the tenth day at midnight; a period that would end on a
 * Saturday, a Sunday or a public holiday runs until the next working day
 * (art. 642 of the Code de procédure civile).
 *
 * The public holidays are the property's place's (Model\PublicHolidays, by its postcode):
 * Alsace-Moselle's Good Friday and 26 December, an overseas department's day of the abolition of
 * slavery, add to the nation's.
 */
final class WithdrawalPeriod
{
    public const DAYS = 10;

    /** Its last day, for a property at that postcode: the buyer may withdraw until that day's midnight. */
    public static function end(\DateTimeInterface $notifiedOn, ?string $postcode = null): \DateTimeImmutable
    {
        $end = \DateTimeImmutable::createFromInterface($notifiedOn)->setTime(0, 0)->modify('+'.self::DAYS.' days');
        while (!self::isWorkingDay($end, $postcode)) {
            $end = $end->modify('+1 day');
        }

        return $end;
    }

    /** Neither a Saturday, a Sunday nor a public holiday there. */
    public static function isWorkingDay(\DateTimeInterface $day, ?string $postcode = null): bool
    {
        return (int) $day->format('N') < 6 && !PublicHolidays::isHoliday($day, $postcode);
    }

    public static function easter(int $year): \DateTimeImmutable
    {
        return PublicHolidays::easter($year);
    }
}
