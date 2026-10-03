<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Decoding;

use DateTimeZone;
use Lenorix\DatadisClient\Data\ApiResult;
use Lenorix\DatadisClient\Data\ConsumptionReading;
use Lenorix\DatadisClient\Time\QuarterHourConvention;
use Lenorix\DatadisClient\Values\MeasurementType;
use SensitiveParameter;

/**
 * Reads a consumption answer: the rows of either version's shape, each placed on its real time.
 *
 * @internal
 */
final class ConsumptionAnswer
{
    /**
     * @param  array<array-key, mixed>  $decoded
     * @return ApiResult<ConsumptionReading>
     */
    public static function result(#[SensitiveParameter] array $decoded, string $endpoint, DateTimeZone $zone, MeasurementType $measurementType): ApiResult
    {
        // Two quarter-hourly conventions are possible; the labels of the answer tell which.
        $quarters = $measurementType === MeasurementType::QuarterHourly ? QuarterHourConvention::detect(self::labels($decoded)) : null;

        // Rows keep their order, so the n-th row with the same date and time is its n-th occurrence.
        $seen = [];
        $decode = static function (#[SensitiveParameter] array $row) use (&$seen, $zone, $measurementType, $quarters): ?ConsumptionReading {
            // Keyed on the day and the label as they are read, not as they are written.
            $day = Fields::date($row, $zone, 'date');
            $key = json_encode([$day?->format('Y-m-d') ?? ($row['date'] ?? null), is_string($row['time'] ?? null) ? trim($row['time']) : ($row['time'] ?? null)]);
            $occurrence = $seen[$key] = ($seen[$key] ?? -1) + 1;

            return ConsumptionReading::fromRow($row, $zone, $measurementType, $occurrence, $quarters);
        };

        // A row without consumption is a month not read yet, not a broken answer: a month of them
        // is an empty result, so the caller can tell it from one Datadis broke.
        return Envelope::build($decoded, 'timeCurve', $endpoint, $decode, static fn (#[SensitiveParameter] array $row): bool => self::lacksReading($row, $zone));
    }

    /**
     * Whether the row holds no reading: a readable date and an hour label with the consumption
     * absent or null, as Datadis sends a month the distributor has not read yet. That is not a
     * fault. A value that is there but cannot be read is, and so is a row whose date or time
     * cannot be read: those make the row unusable.
     *
     * @param  array<array-key, mixed>  $row
     */
    private static function lacksReading(#[SensitiveParameter] array $row, DateTimeZone $zone): bool
    {
        return ($row['consumptionKWh'] ?? null) === null
            && Fields::date($row, $zone, 'date') !== null
            && preg_match('/^\s*\d{1,2}:\d{2}\s*$/D', Fields::text($row, 'time') ?? '') === 1;
    }

    /**
     * The time labels of a consumption answer, in either version's shape.
     *
     * @param  array<array-key, mixed>  $decoded
     * @return list<string>
     */
    private static function labels(#[SensitiveParameter] array $decoded): array
    {
        $rows = array_is_list($decoded) ? $decoded : ($decoded['timeCurve'] ?? []);
        $labels = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && is_string($row['time'] ?? null)) {
                $labels[] = $row['time'];
            }
        }

        return $labels;
    }
}
