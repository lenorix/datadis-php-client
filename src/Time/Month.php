<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Time;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Stringable;

/**
 * A calendar month in the `YYYY/MM` form the private API uses for `startDate` and `endDate`.
 *
 * The API only accepts whole months, refuses future months and refuses anything older than
 * 24 months including the current one (the boundary month exactly two years back is refused).
 */
final readonly class Month implements Stringable
{
    /** Months of history the API serves, counting the current month. */
    public const int HISTORY_MONTHS = 24;

    /** Datadis is a Spanish service: its dates are Madrid dates, and it is assumed to judge its month window by the Madrid calendar (UNVERIFIED). */
    public const string SERVICE_TIME_ZONE = 'Europe/Madrid';

    private function __construct(
        public int $year,
        public int $month,
    ) {}

    public static function of(int $year, int $month): self
    {
        if ($year < 1 || $year > 9999) {
            throw new InvalidArgumentException("Year out of range: {$year}");
        }

        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException("Month out of range: {$month}");
        }

        return new self($year, $month);
    }

    public static function fromString(string $value): self
    {
        if (preg_match('/^(\d{4})\/(0[1-9]|1[0-2])$/D', $value, $matches) !== 1) {
            throw new InvalidArgumentException('Expected a month formatted as YYYY/MM.');
        }

        return self::of((int) $matches[1], (int) $matches[2]);
    }

    public static function fromDate(DateTimeInterface $date): self
    {
        return self::of((int) $date->format('Y'), (int) $date->format('n'));
    }

    /** The current month on the Madrid calendar, whatever the zone of $now. */
    public static function current(DateTimeInterface $now): self
    {
        return self::fromDate(DateTimeImmutable::createFromInterface($now)->setTimezone(new DateTimeZone(self::SERVICE_TIME_ZONE)));
    }

    /**
     * Every month from $from to $to, both included.
     *
     * @return list<self>
     */
    public static function sequence(self $from, self $to): array
    {
        if ($from->isAfter($to)) {
            throw new InvalidArgumentException('The first month must not be after the last one.');
        }

        $months = [];
        for ($i = 0, $count = $to->diffInMonths($from); $i <= $count; $i++) {
            $months[] = $from->addMonths($i);
        }

        return $months;
    }

    public function format(): string
    {
        return sprintf('%04d/%02d', $this->year, $this->month);
    }

    public function addMonths(int $months): self
    {
        $index = $this->index() + $months;

        return self::of(intdiv($index, 12), $index % 12 + 1);
    }

    /** Signed number of months from $other to this month. */
    public function diffInMonths(self $other): int
    {
        return $this->index() - $other->index();
    }

    public function compareTo(self $other): int
    {
        return $this->index() <=> $other->index();
    }

    public function equals(self $other): bool
    {
        return $this->index() === $other->index();
    }

    public function isBefore(self $other): bool
    {
        return $this->index() < $other->index();
    }

    public function isAfter(self $other): bool
    {
        return $this->index() > $other->index();
    }

    public function isFuture(DateTimeInterface $now): bool
    {
        return $this->isAfter(self::current($now));
    }

    /** Whether the API serves this month: not in the future and not older than the history window. */
    public function isWithinHistory(DateTimeInterface $now): bool
    {
        $current = self::current($now);
        $age = $current->diffInMonths($this);

        return $age >= 0 && $age <= self::HISTORY_MONTHS - 1;
    }

    public function __toString(): string
    {
        return $this->format();
    }

    private function index(): int
    {
        return $this->year * 12 + ($this->month - 1);
    }
}
