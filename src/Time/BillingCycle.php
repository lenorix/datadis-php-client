<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Time;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * A billing cycle that starts every month on the same day: from the 15th to the 14th of the next
 * month, or from the 1st to the last day. When the day does not exist in a month (the 31st in
 * April), that month's period starts on its last day.
 *
 * Retailers bill by meter readings, so real invoices may start a day or two off: when the dates of
 * an invoice are known, use BillingPeriod::between() with them.
 */
final readonly class BillingCycle
{
    private DateTimeZone $zone;

    private function __construct(private int $day, ?DateTimeZone $zone)
    {
        $this->zone = $zone ?? new DateTimeZone(Month::SERVICE_TIME_ZONE);
    }

    /**
     * @param  int  $day  the day of the month each period starts on, 1 to 31
     * @param  DateTimeZone|null  $zone  the calendar of the days: Europe/Madrid by default
     *
     * @throws InvalidArgumentException when the day is not between 1 and 31
     */
    public static function monthlyFrom(int $day, ?DateTimeZone $zone = null): self
    {
        if ($day < 1 || $day > 31) {
            throw new InvalidArgumentException("A billing cycle starts on a day from 1 to 31, {$day} given.");
        }

        return new self($day, $zone);
    }

    /** The period a moment falls in, judged on the cycle's calendar. */
    public function periodContaining(DateTimeInterface $moment): BillingPeriod
    {
        $local = DateTimeImmutable::createFromInterface($moment)->setTimezone($this->zone);
        $month = Month::fromDate($local);

        if ((int) $local->format('j') < $this->startDay($month)) {
            $month = $month->addMonths(-1);
        }

        return $this->periodStarting($month);
    }

    /**
     * The periods that overlap a span of days, in order.
     *
     * @return list<BillingPeriod>
     *
     * @throws InvalidArgumentException when the span ends before it starts
     */
    public function periodsBetween(DateTimeInterface $firstDay, DateTimeInterface $lastDay): array
    {
        $first = new DateTimeImmutable($firstDay->format('Y-m-d'), $this->zone);
        $last = new DateTimeImmutable($lastDay->format('Y-m-d'), $this->zone);

        if ($last < $first) {
            throw new InvalidArgumentException('The span of a billing cycle must not end before it starts.');
        }

        $period = $this->periodContaining($first);
        $periods = [$period];

        while ($period->end <= $last) {
            $period = $this->periodContaining($period->end);
            $periods[] = $period;
        }

        return $periods;
    }

    /** The last period that has ended by a moment: the one the next invoice is about. */
    public function lastEndedPeriod(DateTimeInterface $moment): BillingPeriod
    {
        $current = $this->periodContaining($moment);

        return $this->periodContaining($current->start->modify('-1 day'));
    }

    private function periodStarting(Month $month): BillingPeriod
    {
        $next = $month->addMonths(1);

        return BillingPeriod::between(
            $this->dayOf($month),
            $this->dayOf($next)->modify('-1 day'),
            $this->zone,
        );
    }

    private function dayOf(Month $month): DateTimeImmutable
    {
        return $this->firstOf($month)->setDate($month->year, $month->month, $this->startDay($month));
    }

    private function startDay(Month $month): int
    {
        return min($this->day, (int) $this->firstOf($month)->format('t'));
    }

    private function firstOf(Month $month): DateTimeImmutable
    {
        return (new DateTimeImmutable('now', $this->zone))->setDate($month->year, $month->month, 1)->setTime(0, 0);
    }
}
