<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Time;

use DateTimeInterface;
use InvalidArgumentException;
use Lenorix\DatadisClient\Data\Supply;

/**
 * Splits a wanted range of months into the requests worth making.
 *
 * It keeps only the months Datadis serves (the last 24, none in the future) and, given a supply,
 * only the months of its contract: asking for a month without data is a rejected request that still
 * burns the 24 hour repetition window. One month per request is the safe default for consumption,
 * because distributors time out on long ranges.
 */
final class MonthPlanner
{
    /**
     * @param  DateTimeInterface  $now  any zone: the current month is judged on the Madrid calendar, like the client does
     * @return list<array{0: Month, 1: Month}> consecutive, non-overlapping ranges, both ends included
     */
    public static function ranges(Month $from, Month $to, DateTimeInterface $now, int $monthsPerRequest = 1, ?Supply $supply = null): array
    {
        if ($from->isAfter($to)) {
            throw new InvalidArgumentException('The first month must not be after the last one.');
        }

        if ($monthsPerRequest < 1) {
            throw new InvalidArgumentException('At least one month per request.');
        }

        $current = Month::current($now);
        $first = self::latest($from, $current->addMonths(-(Month::HISTORY_MONTHS - 1)));
        $last = self::earliest($to, $current);

        if ($supply?->validDateFrom !== null) {
            $first = self::latest($first, Month::fromDate($supply->validDateFrom));
        }

        if ($supply !== null && ! $supply->isOpenEnded() && $supply->validDateTo !== null) {
            $last = self::earliest($last, Month::fromDate($supply->validDateTo));
        }

        $ranges = [];
        for ($start = $first; ! $start->isAfter($last); $start = $start->addMonths($monthsPerRequest)) {
            $ranges[] = [$start, self::earliest($start->addMonths($monthsPerRequest - 1), $last)];
        }

        return $ranges;
    }

    private static function latest(Month $a, Month $b): Month
    {
        return $a->isAfter($b) ? $a : $b;
    }

    private static function earliest(Month $a, Month $b): Month
    {
        return $a->isBefore($b) ? $a : $b;
    }
}
