<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Time;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A strict parser for the dates Datadis sends: `YYYY/MM/DD` almost everywhere, `YYYY-MM-DD` in the
 * ownership periods of a contract. Anything else, an empty string included, gives null.
 *
 * @internal
 */
final class DatadisDate
{
    /** Midnight of that date in the given zone. */
    public static function tryParse(string $value, DateTimeZone $zone): ?DateTimeImmutable
    {
        if (preg_match('/^(\d{4})([\/-])(\d{2})\2(\d{2})$/D', trim($value), $m) !== 1) {
            return null;
        }

        [$year, $month, $day] = [(int) $m[1], (int) $m[3], (int) $m[4]];

        if ($year < 1 || ! checkdate($month, $day, $year)) {
            return null;
        }

        return new DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day), $zone);
    }
}
