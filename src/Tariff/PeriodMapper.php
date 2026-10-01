<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Tariff;

use DateTimeInterface;

/**
 * Tells which energy period applies to an hour of a day.
 *
 * The package ships the 2.0TD calendar (FixedSchedulePeriods) and the six-period one of 3.0TD and
 * 6.1TD to 6.4TD (SixPeriodSchedule), both from Circular CNMC 3/2020; AccessTariff::schedule() picks
 * one. Implement it yourself to count regional or local holidays, which neither does.
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
