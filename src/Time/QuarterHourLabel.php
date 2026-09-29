<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Time;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A quarter-hourly consumption label (`measurementType=1`), assumed to mark the END of a 15 minute
 * interval, from `00:15` to `24:00`.
 *
 * UNVERIFIED: no source documents the real quarter-hourly format. This follows the hourly convention.
 */
final readonly class QuarterHourLabel
{
    private function __construct(private int $minutes) {}

    public static function tryParse(string $label): ?self
    {
        if (preg_match('/^(\d{2}):(00|15|30|45)$/D', $label, $m) !== 1) {
            return null;
        }

        $minutes = (int) $m[1] * 60 + (int) $m[2];

        return $minutes >= 15 && $minutes <= 1440 ? new self($minutes) : null;
    }

    public static function parse(string $label): self
    {
        return self::tryParse($label) ?? throw new InvalidArgumentException('Not a quarter-hourly label between 00:15 and 24:00.');
    }

    /** Quarter of the day the label describes, 0 to 95. */
    public function index(): int
    {
        return intdiv($this->minutes, 15) - 1;
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
