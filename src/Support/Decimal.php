<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Support;

use Brick\Math\BigDecimal;
use InvalidArgumentException;

/**
 * Converts the numbers Datadis sends into plain decimal strings. Every digit Datadis sent is kept,
 * never rounded; the text is padded with zeros to a minimum number of decimals, so values of one
 * field read alike, and trailing zeros beyond that minimum are dropped.
 *
 * @internal
 */
final class Decimal
{
    private const int MAX_LENGTH = 64;

    public static function of(mixed $value, int $minScale): string
    {
        if ($minScale < 0) {
            throw new InvalidArgumentException('The minimum scale must not be negative.');
        }

        // Plain text without an exponent; trailing zeros of the fraction carry no precision.
        $text = (string) BigDecimal::of(self::literal($value));
        $point = strpos($text, '.');

        if ($point !== false) {
            $text = rtrim(rtrim($text, '0'), '.');
        }

        $point = strpos($text, '.');
        $decimals = $point === false ? 0 : strlen($text) - $point - 1;

        return (string) BigDecimal::of($text)->toScale(max($minScale, $decimals));
    }

    /** Like of(), or null for anything that is not a finite number. */
    public static function tryOf(mixed $value, int $minScale): ?string
    {
        return self::isNumeric($value) ? self::of($value, $minScale) : null;
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
