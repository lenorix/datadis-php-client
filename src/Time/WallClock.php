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
        // The reading as if it were UTC; subtracting each candidate offset gives a real instant.
        // gmmktime() would read the years 0 to 100 as 1970 to 2069.
        $wall = (new DateTimeImmutable($day->format('Y-m-d'), new DateTimeZone('UTC')))->getTimestamp() + $minutes * 60;

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

    /**
     * The UTC offsets the zone uses around the given moment: a day either side reaches past any
     * daylight saving change a wall-clock reading could fall in.
     *
     * @return list<int>
     */
    private static function offsets(DateTimeZone $zone, int $around): array
    {
        return array_values(array_unique([
            self::offsetAt($zone, $around - 86400),
            self::offsetAt($zone, $around),
            self::offsetAt($zone, $around + 86400),
        ]));
    }

    private static function offsetAt(DateTimeZone $zone, int $timestamp): int
    {
        return $zone->getOffset(new DateTimeImmutable('@'.$timestamp));
    }
}
