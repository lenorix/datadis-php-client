<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Decoding;

use DateTimeImmutable;
use DateTimeZone;
use Lenorix\DatadisClient\Support\Decimal;
use Lenorix\DatadisClient\Time\DatadisDate;
use SensitiveParameter;

/**
 * Tolerant readers for the fields of a decoded row.
 *
 * Datadis is loose with types (a code arrives as a string or a number, a decimal as a number or a
 * string, an absent value as null, a missing key or an empty string) and some fields are spelled
 * in more than one way. Readers never throw: a value they cannot read is null, and the untouched
 * row stays available in every DTO as `raw`.
 *
 * @internal
 */
final class Fields
{
    /**
     * The first of $keys that is present and not null, as text. Numbers are written in their
     * shortest exact form. Booleans and arrays are not text.
     *
     * @param  array<array-key, mixed>  $row
     */
    public static function text(#[SensitiveParameter] array $row, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $text = self::scalar($row[$key] ?? null);

            if ($text !== null) {
                return $text;
            }
        }

        return null;
    }

    /** A string, an integer or a finite float as text; anything else is null. */
    public static function scalar(#[SensitiveParameter] mixed $value): ?string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value) => (string) $value,
            is_float($value) && is_finite($value) => Decimal::shortest($value),
            default => null,
        };
    }

    /**
     * Like text() but an empty string counts as absent.
     *
     * @param  array<array-key, mixed>  $row
     */
    public static function nonEmptyText(#[SensitiveParameter] array $row, string ...$keys): ?string
    {
        $text = self::text($row, ...$keys);

        return $text === null || trim($text) === '' ? null : $text;
    }

    /**
     * The exact decimal string of the first numeric key, with at least $minScale decimals, or null
     * when the value is missing, empty or not a number.
     *
     * @param  array<array-key, mixed>  $row
     */
    public static function decimal(#[SensitiveParameter] array $row, int $minScale, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $decimal = Decimal::tryOf($row[$key] ?? null, $minScale);

            if ($decimal !== null) {
                return $decimal;
            }
        }

        return null;
    }

    /** @param  array<array-key, mixed>  $row */
    public static function integer(#[SensitiveParameter] array $row, string ...$keys): ?int
    {
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;

            // Anything that does not fit an int is unknown rather than wrapped or saturated.
            $integer = match (true) {
                is_int($value) => $value,
                is_float($value) => is_finite($value) && floor($value) === $value && abs($value) < 2 ** 63 ? (int) $value : null,
                is_string($value) => ($int = filter_var(trim($value), FILTER_VALIDATE_INT)) === false ? null : $int,
                default => null,
            };

            if ($integer !== null) {
                return $integer;
            }
        }

        return null;
    }

    /**
     * A `YYYY/MM/DD` date at midnight, or null when empty (open ended) or unparseable.
     *
     * @param  array<array-key, mixed>  $row
     */
    public static function date(#[SensitiveParameter] array $row, DateTimeZone $zone, string $key): ?DateTimeImmutable
    {
        $text = self::nonEmptyText($row, $key);

        return $text === null ? null : DatadisDate::tryParse($text, $zone);
    }
}
