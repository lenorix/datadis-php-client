<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Time;

use DateTimeImmutable;
use DateTimeZone;

/** Strict parsers for the date shapes Datadis uses. An empty string (open-ended period) gives null. */
final class DatadisDate
{
    /** `YYYY/MM/DD`, used by almost every field. */
    public static function tryParse(string $value, DateTimeZone $zone): ?DateTimeImmutable
    {
        return self::parse('/^(\d{4})\/(\d{2})\/(\d{2})$/D', $value, $zone);
    }

    /** `YYYY-MM-DD`, used by the ownership periods of a contract. */
    public static function tryParseDashed(string $value, DateTimeZone $zone): ?DateTimeImmutable
    {
        return self::parse('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $zone);
    }

    private static function parse(string $pattern, string $value, DateTimeZone $zone): ?DateTimeImmutable
    {
        if (preg_match($pattern, $value, $m) !== 1) {
            return null;
        }

        [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];

        if ($year < 1 || ! checkdate($month, $day, $year)) {
            return null;
        }

        return new DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day), $zone);
    }
}
