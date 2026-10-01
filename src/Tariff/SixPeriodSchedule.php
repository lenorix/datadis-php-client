<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Tariff;

use DateTimeInterface;
use InvalidArgumentException;
use Lenorix\DatadisClient\Calendar\NationalHolidays;
use Lenorix\DatadisClient\Calendar\Territory;

/**
 * The six-period calendar of 3.0TD and 6.1TD to 6.4TD: Circular CNMC 3/2020, article 7.2 (BOE-A-2020-1066,
 * unchanged by its later amendments).
 *
 * Each month belongs to a season (high, medium-high, medium, low) that depends on the territory. On a
 * working day, 00-08 is P6 and the other hours are "high" or "medium"; the season and the territory
 * decide their period. Weekends, 6 January and the national holidays are P6 all day. Regional and
 * local holidays do not count, as for 2.0TD.
 */
final readonly class SixPeriodSchedule implements PeriodMapper
{
    /** Season of each month (1-12): A high, B medium-high, B1 medium, C low. */
    private const array SEASONS = [
        'peninsula' => [1 => 'A', 'A', 'B', 'C', 'C', 'B1', 'A', 'B1', 'B1', 'C', 'B', 'A'],
        'baleares' => [1 => 'B1', 'B1', 'C', 'C', 'B', 'A', 'A', 'A', 'A', 'B', 'C', 'B1'],
        'canarias' => [1 => 'B1', 'B1', 'B1', 'C', 'C', 'C', 'A', 'A', 'A', 'A', 'B', 'B'],
        'ceuta' => [1 => 'A', 'A', 'B1', 'C', 'C', 'C', 'B', 'A', 'A', 'B', 'B1', 'B1'],
        'melilla' => [1 => 'A', 'B', 'C', 'C', 'C', 'B1', 'A', 'A', 'A', 'B1', 'B1', 'B'],
    ];

    /** The "high" hours of a working day, as [from, to) pairs; the rest from 08 on are "medium". */
    private const array HIGH_HOURS = [
        'peninsula' => [[9, 14], [18, 22]],
        'baleares' => [[10, 15], [18, 22]],
        'canarias' => [[10, 15], [18, 22]],
        'ceuta' => [[10, 15], [19, 23]],
        'melilla' => [[10, 15], [19, 23]],
    ];

    /** Periods of the high and the medium hours for each season. */
    private const array PERIODS = [
        'peninsula' => ['A' => [1, 2], 'B' => [2, 3], 'B1' => [3, 4], 'C' => [4, 5]],
        'baleares' => ['A' => [1, 2], 'B' => [2, 3], 'B1' => [3, 4], 'C' => [4, 5]],
        'canarias' => ['A' => [1, 3], 'B' => [2, 3], 'B1' => [2, 4], 'C' => [4, 5]],
        'ceuta' => ['A' => [1, 4], 'B' => [2, 3], 'B1' => [2, 4], 'C' => [3, 5]],
        'melilla' => ['A' => [1, 2], 'B' => [2, 3], 'B1' => [3, 4], 'C' => [4, 5]],
    ];

    public function __construct(private Territory $territory = Territory::Peninsula) {}

    public function periodFor(DateTimeInterface $day, int $hour): int
    {
        if ($hour < 0 || $hour > 23) {
            throw new InvalidArgumentException("The hour must be between 0 and 23, {$hour} given.");
        }

        if ($hour < 8 || ! NationalHolidays::isWorkingDay($day)) {
            return 6;
        }

        $territory = $this->territory->value;
        $season = self::SEASONS[$territory][(int) $day->format('n')];
        [$high, $medium] = self::PERIODS[$territory][$season];

        foreach (self::HIGH_HOURS[$territory] as [$from, $to]) {
            if ($hour >= $from && $hour < $to) {
                return $high;
            }
        }

        return $medium;
    }
}
