<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Tariff;

use DateTimeInterface;

/**
 * Tells which energy period applies to an hour of a day.
 *
 * Only the 2.0TD fixed schedule ships with the package (FixedSchedulePeriods). The 3.0TD and 6.XTD
 * calendars depend on regulated season tables per territory; implement this interface with your
 * own tables for them.
 */
interface PeriodMapper
{
    /**
     * @param  DateTimeInterface  $day  its civil date (in its own time zone) is what counts
     * @param  int  $hour  hour of the day, 0 to 23 (the `hourOfDay` of a reading)
     * @return int the period number, starting at 1
     */
    public function periodFor(DateTimeInterface $day, int $hour): int;
}
