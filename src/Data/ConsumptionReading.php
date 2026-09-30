<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Data;

use DateTimeImmutable;
use DateTimeZone;
use Lenorix\DatadisClient\Time\DatadisDate;
use Lenorix\DatadisClient\Time\HourLabel;
use Lenorix\DatadisClient\Time\QuarterHourLabel;
use Lenorix\DatadisClient\Values\MeasurementType;
use SensitiveParameter;

/**
 * One consumption row. `date` and `time` are kept exactly as received.
 *
 * The label marks the END of the interval. Never key readings by (date, hour): on the 25 hour day
 * `03:00` appears twice (two different values) and on the 23 hour day it is missing. Rows keep the
 * order in which they arrived.
 *
 * `index` is the hour (0-23) or the quarter (0-95) of the day the row describes; `hourOfDay` is the
 * hour (0-23) in both cases, which is what a tariff period mapper takes.
 *
 * `start`, `end`, `index` and `hourOfDay` are null when the label has an unexpected shape (for example an extra
 * `00:00`); such a row is kept and flagged instead of failing the whole answer.
 */
final readonly class ConsumptionReading
{
    /** @param array<array-key, mixed> $raw */
    private function __construct(
        public ?string $cups,
        public string $date,
        public string $time,
        public DateTimeImmutable $day,
        public ?DateTimeImmutable $start,
        public ?DateTimeImmutable $end,
        public ?int $index,
        public ?int $hourOfDay,
        public string $consumptionKWh,
        public string $obtainMethod,
        public ?string $surplusEnergyKWh,
        public ?string $generationEnergyKWh,
        public ?string $selfConsumptionEnergyKWh,
        public array $raw,
    ) {}

    /**
     * @param  array<array-key, mixed>  $row
     * @param  int  $occurrence  how many rows with the same date and time came before this one; it
     *                           tells the two `03:00` rows of the autumn change day apart
     * @return self|null null when the row has no readable date or consumption (Datadis sends null values)
     */
    public static function fromRow(#[SensitiveParameter] array $row, DateTimeZone $zone, MeasurementType $type, int $occurrence = 0): ?self
    {
        $date = Fields::text($row, 'date');
        $time = Fields::text($row, 'time');
        $consumptionKWh = Fields::decimal($row, 3, 'consumptionKWh');
        $day = $date === null ? null : DatadisDate::tryParse($date, $zone);

        if ($date === null || $time === null || $day === null || $consumptionKWh === null) {
            return null;
        }

        $label = $type === MeasurementType::Hourly ? HourLabel::tryParse($time) : QuarterHourLabel::tryParse($time);
        $interval = $label?->interval($day, $occurrence);

        return new self(
            Fields::text($row, 'cups'),
            $date,
            $time,
            $day,
            $interval[0] ?? null,
            $interval[1] ?? null,
            $label?->index(),
            $label?->hourOfDay(),
            $consumptionKWh,
            trim(Fields::text($row, 'obtainMethod') ?? ''),
            Fields::decimal($row, 3, 'surplusEnergyKWh'),
            Fields::decimal($row, 3, 'generationEnergyKWh'),
            Fields::decimal($row, 3, 'selfConsumptionEnergyKWh'),
            $row,
        );
    }

    public function hasValidTime(): bool
    {
        return $this->start !== null;
    }

    public function isReal(): bool
    {
        return strcasecmp($this->obtainMethod, 'Real') === 0;
    }

    public function isEstimated(): bool
    {
        return in_array(strtolower($this->obtainMethod), ['estimada', 'estimated', 'estimate'], true);
    }
}
