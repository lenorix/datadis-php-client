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
            $key = json_encode([$row['date'] ?? null, $row['time'] ?? null]);
            $occurrence = $seen[$key] = ($seen[$key] ?? -1) + 1;

            return ConsumptionReading::fromRow($row, $zone, $measurementType, $occurrence, $quarters);
        };

        // A row without consumption is a month not read yet, not a broken answer: a month of them
        // is an empty result, so the caller can tell it from one Datadis broke.
        return Envelope::build($decoded, 'timeCurve', $endpoint, $decode, ConsumptionReading::lacksReading(...));
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
