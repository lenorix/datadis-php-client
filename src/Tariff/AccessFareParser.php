<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Tariff;

use Lenorix\DatadisClient\Support\TextNormaliser;

/**
 * Recognises the access tariff in the free-text `accessFare` of a contract by its SHAPE.
 *
 * `accessFare` is not a code: it describes the voltage and power band that defines the tariff, and
 * its wording varies (`BAJA TENSION y POTENCIA <= 15 kW`, `2.0TD PEAJE ATR`,
 * `BAJA TENSION Y POTENCIA  > 15 kW`). Exact string matching has failed in production, so the text
 * is read by band (low voltage 15 kW threshold, or the lower kV bound) and by an explicit alias
 * such as `3.0TD`. When both are present and disagree the answer is null: never guess.
 */
final class AccessFareParser
{
    public static function parse(string $accessFare): ?AccessTariff
    {
        // ≤ and ≥ have no ASCII form and would be dropped by the normalisation, losing the only
        // signal that separates 2.0TD from 3.0TD, so they are spelled out first.
        $text = TextNormaliser::normalise(str_replace(['≤', '≥'], ['<=', '>='], $accessFare));

        $byBand = self::lowVoltageBand($text) ?? self::kilovoltBand($text);
        $byAlias = self::alias($text);

        if ($byBand !== null && $byAlias !== null && $byBand !== $byAlias) {
            return null;
        }

        return $byAlias ?? $byBand;
    }

    private static function lowVoltageBand(string $text): ?AccessTariff
    {
        if (! str_contains($text, 'baja tension')) {
            return null;
        }

        if (preg_match('/potencia\s*(?:<=|menor o igual)[^0-9]{0,15}15\s*kw/', $text) === 1) {
            return AccessTariff::T20TD;
        }

        if (preg_match('/potencia\s*(?:>|superior|mayor)[^0-9]{0,15}15\s*kw/', $text) === 1) {
            return AccessTariff::T30TD;
        }

        return null;
    }

    /** The lower bound alone decides; a decimal comma or dot is accepted. */
    private static function kilovoltBand(string $text): ?AccessTariff
    {
        if (preg_match('/(?:>=|mayor o igual)[^0-9]{0,15}([0-9]+(?:[.,][0-9]+)?)\s*kv/', $text, $m) !== 1) {
            return null;
        }

        $lowerBound = (float) str_replace(',', '.', $m[1]);

        return match (true) {
            $lowerBound < 1 => null,
            $lowerBound < 30 => AccessTariff::T61TD,
            $lowerBound < 72.5 => AccessTariff::T62TD,
            $lowerBound < 145 => AccessTariff::T63TD,
            default => AccessTariff::T64TD,
        };
    }

    private static function alias(string $text): ?AccessTariff
    {
        if (preg_match('/([0-9])\.([0-9])\s*td/', $text, $m) !== 1) {
            return null;
        }

        return AccessTariff::tryFrom("{$m[1]}.{$m[2]}TD");
    }
}
