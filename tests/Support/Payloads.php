<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Tests\Support;

/**
 * Builds consumption payloads programmatically. The shape of the daylight saving days is VERIFIED
 * against real captures (25 rows with `03:00` twice, 23 rows without `03:00`); the values are invented.
 */
final class Payloads
{
    public const string CUPS = 'ES0031300000000001JN0F';

    /**
     * @param  list<string>  $times
     * @return list<array<string, mixed>>
     */
    public static function hourlyRows(string $date, array $times, string $cups = self::CUPS): array
    {
        $rows = [];
        foreach ($times as $i => $time) {
            $rows[] = [
                'cups' => $cups,
                'date' => $date,
                'time' => $time,
                'consumptionKWh' => round(0.1 + $i / 100, 3),
                'obtainMethod' => 'Real',
                'surplusEnergyKWh' => 0,
            ];
        }

        return $rows;
    }

    /** @return list<string> */
    public static function normalDay(): array
    {
        return array_map(fn (int $h): string => sprintf('%02d:00', $h), range(1, 24));
    }

    /** 25 hour day: `03:00` appears twice. @return list<string> */
    public static function autumnDay(): array
    {
        $times = self::normalDay();
        array_splice($times, 3, 0, '03:00');

        return $times;
    }

    /** 23 hour day: `03:00` is missing. @return list<string> */
    public static function springDay(): array
    {
        return array_values(array_filter(self::normalDay(), fn (string $t): bool => $t !== '03:00'));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, string>>  $errors
     */
    public static function envelope(string $key, array $rows, array $errors = []): string
    {
        return json_encode([$key => $rows, 'distributorError' => $errors], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }
}
