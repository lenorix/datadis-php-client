<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Time;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Lenorix\DatadisClient\Data\Supply;
use SensitiveParameter;

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
    public static function ranges(Month $from, Month $to, DateTimeInterface $now, int $monthsPerRequest = 1, #[SensitiveParameter] ?Supply $supply = null): array
    {
        if ($from->isAfter($to)) {
            throw new InvalidArgumentException('The first month must not be after the last one.');
        }

        if ($monthsPerRequest < 1) {
            throw new InvalidArgumentException('At least one month per request.');
        }

        // No range is longer than the history Datadis serves, so a larger number means one request.
        $monthsPerRequest = min($monthsPerRequest, Month::HISTORY_MONTHS);
        $current = Month::current($now);
        $first = self::later($from, $current->addMonths(-(Month::HISTORY_MONTHS - 1)));
        $last = self::earliest($to, $current);

        if ($supply?->validDateFrom !== null) {
            $first = self::later($first, Month::fromDate($supply->validDateFrom));
        }

        if ($supply?->validDateTo !== null) {
            $last = self::earliest($last, Month::fromDate($supply->validDateTo));
        }

        $ranges = [];
        for ($start = $first; ! $start->isAfter($last); $start = $start->addMonths($monthsPerRequest)) {
            $ranges[] = [$start, self::earliest($start->addMonths($monthsPerRequest - 1), $last)];
        }

        return $ranges;
    }

    /**
     * The range to ask today for the current month in a sync that runs every day.
     *
     * Datadis refuses an identical query for 24 hours, so asking the same range every day fails
     * whenever a run starts a little earlier than the day before. The range alternates with the
     * civil day (Madrid): the current month alone on even days, the previous and the current month
     * on odd days, so consecutive days never send the same query and each range comes back about
     * every 48 hours. The previous month is refreshed every other day too, which also brings its
     * last days, published after it ended. A second run on the same day gets the same range, which
     * the ledger refuses: taking the other one would block tomorrow's. Schedule the job at a fixed
     * hour in Madrid time, well clear of midnight: one fixed in UTC can run twice on the same Madrid
     * day, or skip one, when the clocks change, and that run is refused.
     *
     * Given a supply, the range keeps to its contract: a contract that starts this month only has
     * the current month (refreshed every other day), and one that ended before this month, or
     * starts after it, has nothing to refresh (an empty list).
     *
     * @param  DateTimeInterface  $now  any zone: the civil day and the current month are those of Madrid
     * @return list<array{0: Month, 1: Month}> one range, or none
     */
    public static function latest(DateTimeInterface $now, #[SensitiveParameter] ?Supply $supply = null): array
    {
        $current = Month::current($now);
        $previous = $current->addMonths(-1);
        $ended = $supply?->validDateTo !== null && Month::fromDate($supply->validDateTo)->isBefore($current);
        $notStarted = $supply?->validDateFrom !== null && Month::fromDate($supply->validDateFrom)->isAfter($current);

        if ($ended || $notStarted) {
            return [];
        }

        $startsThisMonth = $supply?->validDateFrom !== null && $previous->isBefore(Month::fromDate($supply->validDateFrom));

        return [[self::civilDayNumber($now) % 2 === 1 && ! $startsThisMonth ? $previous : $current, $current]];
    }

    /** Days since 1970-01-01 of the Madrid civil date, so consecutive days always differ in parity. */
    private static function civilDayNumber(DateTimeInterface $now): int
    {
        $date = DateTimeImmutable::createFromInterface($now)->setTimezone(new DateTimeZone(Month::SERVICE_TIME_ZONE))->format('Y-m-d');
        $days = intdiv((new DateTimeImmutable($date, new DateTimeZone('UTC')))->getTimestamp(), 86400);

        return $days < 0 ? -$days : $days;
    }

    private static function later(Month $a, Month $b): Month
    {
        return $a->isAfter($b) ? $a : $b;
    }

    private static function earliest(Month $a, Month $b): Month
    {
        return $a->isBefore($b) ? $a : $b;
    }
}
