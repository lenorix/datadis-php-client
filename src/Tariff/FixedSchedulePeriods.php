<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Tariff;

use DateTimeInterface;
use InvalidArgumentException;
use Lenorix\DatadisClient\Calendar\NationalHolidays;
use Lenorix\DatadisClient\Calendar\Territory;

/**
 * The 2.0TD schedule (Circular CNMC 3/2020): the same every working day of the year, all valley on
 * weekends and national holidays.
 *
 * Peninsula, Baleares and Canarias: P1 10-14 and 18-22, P2 8-10, 14-18 and 22-24, P3 0-8.
 * Ceuta and Melilla: P1 11-15 and 19-23, P2 8-11, 15-19 and 23-24, P3 0-8 (the peak and flat
 * blocks one hour later; valley still ends at 8).
 */
final readonly class FixedSchedulePeriods implements PeriodMapper
{
    /** Period of each hour 0..23 on a working day. */
    private const array PENINSULA = [3, 3, 3, 3, 3, 3, 3, 3, 2, 2, 1, 1, 1, 1, 2, 2, 2, 2, 1, 1, 1, 1, 2, 2];

    private const array CEUTA_MELILLA = [3, 3, 3, 3, 3, 3, 3, 3, 2, 2, 2, 1, 1, 1, 1, 2, 2, 2, 2, 1, 1, 1, 1, 2];

    public function __construct(private Territory $territory = Territory::Peninsula) {}

    public function periodFor(DateTimeInterface $day, int $hour): int
    {
        if ($hour < 0 || $hour > 23) {
            throw new InvalidArgumentException("The hour must be between 0 and 23, {$hour} given.");
        }

        if (! NationalHolidays::isWorkingDay($day)) {
            return 3;
        }

        $table = match ($this->territory) {
            Territory::Ceuta, Territory::Melilla => self::CEUTA_MELILLA,
            default => self::PENINSULA,
        };

        return $table[$hour];
    }
}
