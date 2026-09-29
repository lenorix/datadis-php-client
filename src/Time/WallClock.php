<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Time;

use DateTimeImmutable;

/**
 * Builds local wall-clock instants for a day.
 *
 * Datadis labels are local civil time without an offset, so they are placed on the wall clock of the
 * day's time zone. On a daylight saving change day the repeated hour keeps its label (the first
 * occurrence is used) and the skipped hour has no data at all.
 *
 * @internal
 */
final class WallClock
{
    public static function at(DateTimeImmutable $day, int $minutesFromMidnight): DateTimeImmutable
    {
        $midnight = $day->setTime(0, 0);
        $days = intdiv($minutesFromMidnight, 1440);
        $rest = $minutesFromMidnight % 1440;

        if ($days > 0) {
            $midnight = $midnight->modify("+{$days} day");
        }

        return $midnight->setTime(intdiv($rest, 60), $rest % 60);
    }
}
