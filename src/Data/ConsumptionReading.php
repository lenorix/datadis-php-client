<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Data;

use DateTimeImmutable;
use DateTimeZone;
use Lenorix\DatadisClient\Time\DatadisDate;
use Lenorix\DatadisClient\Time\HourLabel;
use Lenorix\DatadisClient\Time\QuarterHourLabel;
use Lenorix\DatadisClient\Values\MeasurementType;

/**
 * One consumption row. `date` and `time` are kept exactly as received.
 *
 * The label marks the END of the interval. Never key readings by (date, hour): on the 25 hour day
 * `03:00` appears twice (two different values) and on the 23 hour day it is missing. Rows keep the
 * order in which they arrived.
 *
 * `start`, `end` and `index` are null when the label has an unexpected shape (for example an extra
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
        public string $kWh,
        public string $obtainMethod,
        public ?string $surplusKWh,
        public ?string $generationKWh,
        public ?string $selfConsumptionKWh,
        public array $raw,
    ) {}

    /**
     * @param  array<array-key, mixed>  $row
     * @return self|null null when the row has no readable date or consumption (Datadis sends null values)
     */
    public static function fromRow(array $row, DateTimeZone $zone, MeasurementType $type): ?self
    {
        $date = Fields::text($row, 'date');
        $time = Fields::text($row, 'time');
        $kWh = Fields::decimal($row, 3, 'consumptionKWh');
        $day = $date === null ? null : DatadisDate::tryParse($date, $zone);

        if ($date === null || $time === null || $day === null || $kWh === null) {
            return null;
        }

        $label = $type === MeasurementType::Hourly ? HourLabel::tryParse($time) : QuarterHourLabel::tryParse($time);
        $interval = $label?->interval($day);

        return new self(
            Fields::text($row, 'cups'),
            $date,
            $time,
            $day,
            $interval[0] ?? null,
            $interval[1] ?? null,
            $label?->index(),
            $kWh,
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
