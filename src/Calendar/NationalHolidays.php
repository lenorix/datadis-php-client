<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Calendar;

use DateTimeInterface;

/**
 * The national holidays that count for tariff periods.
 *
 * Circular CNMC 3/2020 counts national holidays "con exclusión tanto de los festivos sustituibles
 * como de los que no tienen fecha fija", so only fixed-date ones are here. Good Friday is a
 * national holiday but moves with Easter, so for tariff periods it is an ordinary weekday.
 * Regional and local holidays are not national and are out of scope.
 */
final class NationalHolidays
{
    /** Month-day pairs. */
    private const array FIXED = [
        '01-01', // New Year
        '01-06', // Epiphany
        '05-01', // Labour Day
        '08-15', // Assumption
        '10-12', // National Day
        '11-01', // All Saints
        '12-06', // Constitution Day
        '12-08', // Immaculate Conception
        '12-25', // Christmas
    ];

    /** Uses the civil date of the instant in its own time zone. */
    public static function isHoliday(DateTimeInterface $day): bool
    {
        return in_array($day->format('m-d'), self::FIXED, true);
    }

    public static function isWorkingDay(DateTimeInterface $day): bool
    {
        return (int) $day->format('N') < 6 && ! self::isHoliday($day);
    }
}
