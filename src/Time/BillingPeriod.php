<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Time;

use Brick\Math\BigDecimal;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Lenorix\DatadisClient\Data\ConsumptionReading;

/**
 * The days an invoice covers, both included, on the civil calendar (Madrid by default): `del 15/08
 * al 14/09`. Datadis knows nothing of billing: the retailer sets the days, and they may move with
 * the meter readings, so the dates printed on an invoice are the reliable ones.
 *
 * A period tells which months to ask Datadis for, which readings fall in it, their total, and
 * whether the readings already reach its end (Datadis publishes a day or two late).
 */
final readonly class BillingPeriod
{
    /** The first day at 00:00, in the period's zone. */
    public DateTimeImmutable $start;

    /** The day after the last one at 00:00: the period ends there, not included. */
    public DateTimeImmutable $end;

    private function __construct(DateTimeImmutable $start, DateTimeImmutable $end)
    {
        $this->start = $start;
        $this->end = $end;
    }

    /**
     * @param  DateTimeInterface  $firstDay  only its date counts
     * @param  DateTimeInterface  $lastDay  only its date counts; included
     * @param  DateTimeZone|null  $zone  the calendar of the dates: Europe/Madrid by default, Atlantic/Canary for the Canary Islands
     *
     * @throws InvalidArgumentException when the last day is before the first
     */
    public static function between(DateTimeInterface $firstDay, DateTimeInterface $lastDay, ?DateTimeZone $zone = null): self
    {
        $zone ??= new DateTimeZone(Month::SERVICE_TIME_ZONE);
        $start = new DateTimeImmutable($firstDay->format('Y-m-d'), $zone);
        // The next calendar day, read in the zone: where a change of the clocks skips midnight, the
        // first day starts at 01:00, and adding a day to that would end the period an hour late.
        $nextDay = (new DateTimeImmutable($lastDay->format('Y-m-d'), new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
        $end = new DateTimeImmutable($nextDay, $zone);

        if ($end <= $start) {
            throw new InvalidArgumentException('The last day of a billing period must not be before the first.');
        }

        return new self($start, $end);
    }

    /** The last day included, at 00:00. */
    public function lastDay(): DateTimeImmutable
    {
        return $this->end->modify('-1 day');
    }

    public function days(): int
    {
        return (int) $this->start->diff($this->end)->days;
    }

    /**
     * The months to ask Datadis for to cover the period, as `[$startDate, $endDate]` for
     * getConsumptionDataOf() or MonthPlanner::ranges().
     *
     * @return array{0: Month, 1: Month}
     */
    public function months(): array
    {
        return [Month::fromDate($this->start), Month::fromDate($this->lastDay())];
    }

    /** Whether an instant falls in the period: from its start, before its end. */
    public function contains(DateTimeInterface $instant): bool
    {
        $at = $instant->getTimestamp();

        return $at >= $this->start->getTimestamp() && $at < $this->end->getTimestamp();
    }

    /**
     * The readings of the period, by the start of their interval. A reading whose time could not
     * be placed (an extra `00:00` row, labels of no known convention) is left out: its energy may
     * belong to another hour or repeat one, so it is not added to a total. It stays in the result
     * for whoever wants to look at it.
     *
     * @param  iterable<ConsumptionReading>  $readings
     * @return list<ConsumptionReading>
     */
    public function readingsOf(iterable $readings): array
    {
        $in = [];

        foreach ($readings as $reading) {
            if ($reading->start !== null && $this->contains($reading->start)) {
                $in[] = $reading;
            }
        }

        return $in;
    }

    /**
     * The consumption of the readings of the period, in kWh, exact: only readings placed in time,
     * as readingsOf() takes them.
     *
     * @param  iterable<ConsumptionReading>  $readings
     */
    public function totalKWh(iterable $readings): string
    {
        $total = BigDecimal::zero();

        foreach ($this->readingsOf($readings) as $reading) {
            $total = $total->plus($reading->consumptionKWh);
        }

        return (string) $total->toScale(max(3, $total->getScale()));
    }

    /**
     * Whether the readings reach the end of the period: one of them ends at its end. Until then
     * the total is not the invoice's, since Datadis publishes a day or two late.
     *
     * @param  iterable<ConsumptionReading>  $readings
     */
    public function isCoveredBy(iterable $readings): bool
    {
        foreach ($readings as $reading) {
            if ($reading->end !== null && $reading->end->getTimestamp() === $this->end->getTimestamp()) {
                return true;
            }
        }

        return false;
    }
}
