<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Converts the numbers Datadis sends into plain decimal strings with a fixed scale (half-up rounding).
 *
 * @internal
 */
final class Decimal
{
    private const int MAX_LENGTH = 64;

    public static function of(mixed $value, int $scale): string
    {
        if ($scale < 0) {
            throw new InvalidArgumentException('The scale must not be negative.');
        }

        return (string) BigDecimal::of(self::literal($value))->toScale($scale, RoundingMode::HalfUp);
    }

    public static function isNumeric(mixed $value): bool
    {
        try {
            self::literal($value);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /** The shortest text that reads back as the same float, whatever the `precision` and `serialize_precision` settings. */
    public static function shortest(float $value): string
    {
        $previous = ini_set('serialize_precision', '-1');

        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } finally {
            if ($previous !== false) {
                ini_set('serialize_precision', $previous);
            }
        }
    }

    private static function literal(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('Not a finite number.');
            }

            return self::shortest($value);
        }

        // Bounded on purpose: an exponent like 1e300000000 would need gigabytes of digits. Three
        // exponent digits cover every float, and no real value needs more than 64 characters.
        if (is_string($value) && strlen($value) <= self::MAX_LENGTH && preg_match('/^-?\d+(?:\.\d+)?(?:[eE][+-]?\d{1,3})?$/D', $value) === 1) {
            return $value;
        }

        throw new InvalidArgumentException('Not a numeric value.');
    }
}
