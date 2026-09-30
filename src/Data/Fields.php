<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Data;

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
            $value = $row[$key] ?? null;

            if (is_string($value)) {
                return $value;
            }

            if (is_int($value)) {
                return (string) $value;
            }

            if (is_float($value) && is_finite($value)) {
                return json_encode($value, JSON_THROW_ON_ERROR);
            }
        }

        return null;
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
     * A decimal string with a fixed scale, or null when the value is missing, empty or not a number.
     *
     * @param  array<array-key, mixed>  $row
     */
    public static function decimal(#[SensitiveParameter] array $row, int $scale, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;

            if ($value !== null && $value !== '' && Decimal::isNumeric($value)) {
                return Decimal::of($value, $scale);
            }
        }

        return null;
    }

    /** @param  array<array-key, mixed>  $row */
    public static function integer(#[SensitiveParameter] array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;

        return match (true) {
            is_int($value) => $value,
            is_float($value) => is_finite($value) && floor($value) === $value ? (int) $value : null,
            is_string($value) && preg_match('/^-?\d+$/D', trim($value)) === 1 => (int) trim($value),
            default => null,
        };
    }

    /**
     * A `YYYY/MM/DD` date at midnight, or null when empty (open ended) or unparseable.
     *
     * @param  array<array-key, mixed>  $row
     */
    public static function date(#[SensitiveParameter] array $row, DateTimeZone $zone, string $key): ?DateTimeImmutable
    {
        $text = self::nonEmptyText($row, $key);

        return $text === null ? null : DatadisDate::tryParse(trim($text), $zone);
    }
}
