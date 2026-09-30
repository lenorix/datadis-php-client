<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient;

use Lenorix\DatadisClient\Data\Supply;
use Lenorix\DatadisClient\Values\Cups;

/**
 * Chooses the supply row that belongs to a CUPS.
 *
 * Distributors inconsistently include the optional frontier suffix, so rows match on the first 20
 * characters. One CUPS can have several rows (successive contracts, a distributor change): the open
 * contract wins, otherwise the one that started last. Dates are compared as dates, not as strings.
 */
final class SupplyMatcher
{
    /** @param list<Supply> $supplies */
    public static function pick(array $supplies, Cups $cups): ?Supply
    {
        $best = null;

        foreach ($supplies as $supply) {
            // Compared as text: a listed CUPS with an unexpected shape must not stop the search.
            if (substr(strtoupper(trim($supply->cups)), 0, 20) !== $cups->base()) {
                continue;
            }

            if ($best === null || self::isBetter($supply, $best)) {
                $best = $supply;
            }
        }

        return $best;
    }

    private static function isBetter(Supply $candidate, Supply $best): bool
    {
        if ($candidate->isOpenEnded() !== $best->isOpenEnded()) {
            return $candidate->isOpenEnded();
        }

        $candidateStart = $candidate->validFrom?->getTimestamp() ?? PHP_INT_MIN;
        $bestStart = $best->validFrom?->getTimestamp() ?? PHP_INT_MIN;

        return $candidateStart > $bestStart;
    }
}
