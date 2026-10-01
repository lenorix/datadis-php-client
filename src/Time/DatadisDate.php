<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Time;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A strict parser for the dates Datadis sends: `YYYY/MM/DD` almost everywhere, `YYYY-MM-DD` in the
 * ownership periods of a contract, and `YYYY-MM-DD HH:MM:SS.f` in the authorization list (verified).
 * Anything else, an empty string included, gives null.
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

        return self::build((int) $m[1], (int) $m[3], (int) $m[4], 0, 0, 0, $zone);
    }

    /** A date and a time of day, `YYYY-MM-DD HH:MM:SS` with optional fractions of a second, which are dropped. */
    public static function tryParseDateTime(string $value, DateTimeZone $zone): ?DateTimeImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})(?:\.\d+)?$/D', trim($value), $m) !== 1) {
            return null;
        }

        [$hour, $minute, $second] = [(int) $m[4], (int) $m[5], (int) $m[6]];

        if ($hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        return self::build((int) $m[1], (int) $m[2], (int) $m[3], $hour, $minute, $second, $zone);
    }

    private static function build(int $year, int $month, int $day, int $hour, int $minute, int $second, DateTimeZone $zone): ?DateTimeImmutable
    {
        if ($year < 1 || ! checkdate($month, $day, $year)) {
            return null;
        }

        return new DateTimeImmutable(sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second), $zone);
    }
}
