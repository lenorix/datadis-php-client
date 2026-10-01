<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Time;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A quarter-hourly consumption label (`measurementType=1`), in either convention of
 * QuarterHourConvention. It is kept as the minute of the day the quarter ends at.
 *
 * UNVERIFIED: no source documents the real quarter-hourly format; see QuarterHourConvention.
 */
final readonly class QuarterHourLabel
{
    private function __construct(private int $minutes) {}

    public static function tryParse(string $label, QuarterHourConvention $convention = QuarterHourConvention::QuarterEnd): ?self
    {
        if (preg_match('/^(\d{2}):(00|15|30|45)$/D', $label, $m) !== 1) {
            return null;
        }

        [$hour, $minute] = [(int) $m[1], (int) $m[2]];

        $end = match ($convention) {
            QuarterHourConvention::QuarterEnd => $hour * 60 + $minute,
            QuarterHourConvention::HourEndingWithStartMinute => $hour >= 1 ? ($hour - 1) * 60 + $minute + 15 : 0,
        };

        return $end >= 15 && $end <= 1440 ? new self($end) : null;
    }

    public static function parse(string $label, QuarterHourConvention $convention = QuarterHourConvention::QuarterEnd): self
    {
        return self::tryParse($label, $convention) ?? throw new InvalidArgumentException('Not a quarter-hourly label in that convention.');
    }

    /** Quarter of the day the label describes, 0 to 95. */
    public function index(): int
    {
        return intdiv($this->minutes, 15) - 1;
    }

    /** Hour of the day the quarter falls in, 0 to 23: `11:00` is the last quarter of hour 10. */
    public function hourOfDay(): int
    {
        return intdiv($this->index(), 4);
    }

    /**
     * Start and end of the quarter on the given day, or null when the wall clock never showed it.
     *
     * @param  int  $occurrence  0 for the first row with this label on the day, 1 for the second
     * @return array{DateTimeImmutable, DateTimeImmutable}|null
     */
    public function interval(DateTimeImmutable $day, int $occurrence = 0): ?array
    {
        $end = WallClock::instants($day, $this->minutes, true)[$occurrence] ?? null;

        return $end === null ? null : [WallClock::before($end, 900), $end];
    }
}
