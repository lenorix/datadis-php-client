<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Time;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Turns a local wall-clock reading of a day into real instants.
 *
 * Datadis labels are local civil time without an offset. On the autumn change day one wall-clock
 * hour happens twice, so a reading can name two instants; on the spring change day one hour never
 * happens, so a reading can name none. This returns every instant a reading can name, earliest
 * first, and callers pick one by the order in which the reading appeared.
 *
 * @internal
 */
final class WallClock
{
    /**
     * @param  DateTimeImmutable  $day  its date and time zone are used, not its time
     * @param  int  $minutes  minutes after midnight on the wall clock; 1440 is the next midnight
     * @param  bool  $asIntervalEnd  true when the reading ends an interval: the offset that matters
     *                               is the one in effect just before the instant
     * @return list<DateTimeImmutable>
     */
    public static function instants(DateTimeImmutable $day, int $minutes, bool $asIntervalEnd): array
    {
        $zone = $day->getTimezone();
        [$year, $month, $date] = array_map('intval', explode('-', $day->format('Y-m-d')));
        // The reading as if it were UTC; subtracting each candidate offset gives a real instant.
        $wall = gmmktime(0, 0, 0, $month, $date, $year) + $minutes * 60;

        $instants = [];
        foreach (self::offsets($zone, $wall) as $offset) {
            $instant = $wall - $offset;

            if (self::offsetAt($zone, $asIntervalEnd ? $instant - 1 : $instant) === $offset) {
                $instants[$instant] = (new DateTimeImmutable('@'.$instant))->setTimezone($zone);
            }
        }

        ksort($instants);

        return array_values($instants);
    }

    /** The instant $seconds before $instant in real (elapsed) time, in the same zone. modify() would count wall-clock time. */
    public static function before(DateTimeImmutable $instant, int $seconds): DateTimeImmutable
    {
        return (new DateTimeImmutable('@'.($instant->getTimestamp() - $seconds)))->setTimezone($instant->getTimezone());
    }

    /** @return list<int> the UTC offsets the zone uses around the given moment */
    private static function offsets(DateTimeZone $zone, int $around): array
    {
        $transitions = $zone->getTransitions($around - 2 * 86400, $around + 2 * 86400);
        $offsets = [self::offsetAt($zone, $around)];

        if (is_array($transitions)) {
            foreach ($transitions as $transition) {
                $offsets[] = (int) $transition['offset'];
            }
        }

        return array_values(array_unique($offsets));
    }

    private static function offsetAt(DateTimeZone $zone, int $timestamp): int
    {
        return $zone->getOffset(new DateTimeImmutable('@'.$timestamp));
    }
}
