<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Time;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * An hourly consumption label, `01:00` to `24:00`, which marks the END of the hour it describes:
 * `01:00` is 00:00-01:00 and `24:00` is 23:00-24:00 (verified against real captures).
 *
 * Anything else, such as `00:00`, is not a valid hourly label and is rejected instead of guessed.
 */
final readonly class HourLabel
{
    private function __construct(public int $hour) {}

    public static function tryParse(string $label): ?self
    {
        if (preg_match('/^(0[1-9]|1\d|2[0-4]):00$/D', $label, $m) !== 1) {
            return null;
        }

        return new self((int) $m[1]);
    }

    public static function parse(string $label): self
    {
        return self::tryParse($label) ?? throw new InvalidArgumentException('Not an hourly label between 01:00 and 24:00.');
    }

    /** Hour of the day the label describes, 0 to 23. */
    public function index(): int
    {
        return $this->hour - 1;
    }

    /**
     * Start and end of the interval on the given day (its date and time zone), or null when the
     * wall clock never showed that hour (the hour skipped by the spring change).
     *
     * @param  int  $occurrence  0 for the first row with this label on the day, 1 for the second:
     *                           on the autumn change day the repeated hour appears twice
     * @return array{DateTimeImmutable, DateTimeImmutable}|null
     */
    public function interval(DateTimeImmutable $day, int $occurrence = 0): ?array
    {
        $end = WallClock::instants($day, $this->hour * 60, true)[$occurrence] ?? null;

        return $end === null ? null : [WallClock::before($end, 3600), $end];
    }
}
