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
                'surplusEnergyKWh' => null,
                'generationEnergyKWh' => null,
                'selfConsumptionEnergyKWh' => null,
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

    /**
     * A month as Datadis really sends it for a supply without self-consumption (verified shape:
     * the three self-consumption fields are present and null), with invented values. Days are
     * built from the real hours of each day in Madrid, so change days have 23 or 25 rows.
     *
     * @return list<array<string, mixed>>
     */
    public static function realMonth(int $year, int $month, ?int $untilDay = null, string $cups = self::CUPS): array
    {
        $zone = new \DateTimeZone('Europe/Madrid');
        $rows = [];
        $days = $untilDay ?? (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), $zone))->format('t');

        for ($day = 1; $day <= $days; $day++) {
            $midnight = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day), $zone);
            $next = $midnight->modify('+1 day')->getTimestamp();

            for ($t = $midnight->getTimestamp(); $t < $next; $t += 3600) {
                $offset = $zone->getOffset(new \DateTimeImmutable('@'.($t + 3599)));
                $rows[] = [
                    'cups' => $cups,
                    'date' => $midnight->format('Y/m/d'),
                    'time' => $t + 3600 === $next ? '24:00' : gmdate('H:00', $t + 3600 + $offset),
                    'consumptionKWh' => round(0.2 + (count($rows) % 17) / 10, 3),
                    'obtainMethod' => 'Real',
                    'surplusEnergyKWh' => null,
                    'generationEnergyKWh' => null,
                    'selfConsumptionEnergyKWh' => null,
                ];
            }
        }

        return $rows;
    }
}
