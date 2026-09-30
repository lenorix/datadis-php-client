<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Data;

use DateTimeImmutable;
use DateTimeZone;
use Lenorix\DatadisClient\Decoding\Fields;
use Lenorix\DatadisClient\Time\DatadisDate;
use Lenorix\DatadisClient\Time\TimeInstant;
use SensitiveParameter;

/**
 * The maximum power of a day, with the instant it was reached.
 *
 * The unit is kW (verified: 3.516 against 3.45 kW contracted; the official documentation says W,
 * which is wrong). Datadis sends one row per period. The time seems to mark the END of the quarter
 * hour, like consumption labels: a real `00:00` row in period 2 only fits 23:45-24:00 of the
 * previous day. `instant` is that moment either way.
 * `period` is kept as received: it appears as `"1"`..`"6"`, and some sources show `P1` or
 * VALLE/LLANO/PUNTA. `periodNumber()` understands the numeric spellings.
 */
final readonly class MaxPowerReading
{
    /** @param array<array-key, mixed> $raw */
    private function __construct(
        public ?string $cups,
        public string $date,
        public string $time,
        public ?DateTimeImmutable $instant,
        public string $maxPower,
        public ?string $period,
        public array $raw,
    ) {}

    /**
     * @param  array<array-key, mixed>  $row
     * @return self|null null when the row has no readable date or value
     */
    public static function fromRow(#[SensitiveParameter] array $row, DateTimeZone $zone): ?self
    {
        $date = Fields::text($row, 'date');
        $time = Fields::text($row, 'time');
        $power = Fields::decimal($row, 3, 'maxPower');

        if ($date === null || $time === null || $power === null || DatadisDate::tryParse($date, $zone) === null) {
            return null;
        }

        return new self(
            Fields::text($row, 'cups'),
            $date,
            $time,
            TimeInstant::tryParse($date, $time, $zone),
            $power,
            Fields::nonEmptyText($row, 'period'),
            $row,
        );
    }

    public function hasValidTime(): bool
    {
        return $this->instant !== null;
    }

    /** The power period 1 to 6 when the period is numeric (`"3"`, `"P3"`), otherwise null. */
    public function periodNumber(): ?int
    {
        return $this->period !== null && preg_match('/^P?([1-6])$/Di', trim($this->period), $m) === 1 ? (int) $m[1] : null;
    }
}
