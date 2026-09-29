<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/** Converts the numbers Datadis sends into plain decimal strings with a fixed scale (half-up rounding). */
final class Decimal
{
    public const int ENERGY_SCALE = 3;

    public const int POWER_SCALE = 2;

    private const int MAX_LENGTH = 64;

    public static function of(mixed $value, int $scale): string
    {
        return BigDecimal::of(self::literal($value))->toScale($scale, RoundingMode::HalfUp)->toString();
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

    private static function literal(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('Not a finite number.');
            }

            // The shortest round-trip form; a plain string cast would use the `precision` ini setting.
            return json_encode($value, JSON_THROW_ON_ERROR);
        }

        // Bounded on purpose: an exponent like 1e300000000 would need gigabytes of digits. Three
        // exponent digits cover every float, and no real value needs more than 64 characters.
        if (is_string($value) && strlen($value) <= self::MAX_LENGTH && preg_match('/^-?\d+(?:\.\d+)?(?:[eE][+-]?\d{1,3})?$/D', $value) === 1) {
            return $value;
        }

        throw new InvalidArgumentException('Not a numeric value.');
    }
}
