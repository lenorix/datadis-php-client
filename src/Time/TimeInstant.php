<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Time;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A `date` + `time` pair that names an instant (for example a maximum power reading at `09:45`),
 * as opposed to an interval label. `24:00` is understood as midnight of the next day. A time in
 * the repeated autumn hour is read as its first occurrence; one in the skipped spring hour is null.
 *
 * @internal
 */
final class TimeInstant
{
    public static function tryParse(string $date, string $time, DateTimeZone $zone): ?DateTimeImmutable
    {
        $day = DatadisDate::tryParse($date, $zone);

        if ($day === null || preg_match('/^(\d{2}):(\d{2})$/D', $time, $m) !== 1) {
            return null;
        }

        [$hour, $minute] = [(int) $m[1], (int) $m[2]];

        if ($hour > 24 || $minute > 59 || ($hour === 24 && $minute !== 0)) {
            return null;
        }

        return WallClock::instants($day, $hour * 60 + $minute, false)[0] ?? null;
    }
}
