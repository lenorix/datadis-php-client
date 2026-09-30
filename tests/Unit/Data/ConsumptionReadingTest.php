<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Data\ConsumptionReading;
use Lenorix\DatadisClient\Tests\Support\Payloads;
use Lenorix\DatadisClient\Values\MeasurementType;

$madrid = new DateTimeZone('Europe/Madrid');
$hourly = MeasurementType::Hourly;

it('decodes an hourly row with its interval and decimal values', function () use ($madrid, $hourly) {
    $row = ['cups' => 'ES0031300000000001JN0F', 'date' => '2026/01/01', 'time' => '02:00', 'consumptionKWh' => 0.301, 'obtainMethod' => 'Real', 'surplusEnergyKWh' => 0];
    $reading = ConsumptionReading::fromRow($row, $madrid, $hourly);

    expect($reading->kWh)->toBe('0.301')
        ->and($reading->surplusKWh)->toBe('0.000')
        ->and($reading->generationKWh)->toBeNull()
        ->and($reading->date)->toBe('2026/01/01')
        ->and($reading->time)->toBe('02:00')
        ->and($reading->index)->toBe(1)
        ->and($reading->start?->format('Y-m-d H:i'))->toBe('2026-01-01 01:00')
        ->and($reading->end?->format('Y-m-d H:i'))->toBe('2026-01-01 02:00')
        ->and($reading->hasValidTime())->toBeTrue()
        ->and($reading->isReal())->toBeTrue()
        ->and($reading->isEstimated())->toBeFalse();
});

it('places 24:00 in the last hour of the day', function () use ($madrid, $hourly) {
    $reading = ConsumptionReading::fromRow(['date' => '2026/12/31', 'time' => '24:00', 'consumptionKWh' => 1.5, 'obtainMethod' => 'Estimada'], $madrid, $hourly);

    expect($reading->index)->toBe(23)
        ->and($reading->start?->format('Y-m-d H:i'))->toBe('2026-12-31 23:00')
        ->and($reading->end?->format('Y-m-d H:i'))->toBe('2027-01-01 00:00')
        ->and($reading->isEstimated())->toBeTrue()
        ->and($reading->cups)->toBeNull();
});

it('keeps a row with an unrecognised time and flags it', function (string $time) use ($madrid, $hourly) {
    $reading = ConsumptionReading::fromRow(['date' => '2026/01/01', 'time' => $time, 'consumptionKWh' => 1.0], $madrid, $hourly);

    expect($reading)->not->toBeNull()
        ->and($reading->hasValidTime())->toBeFalse()
        ->and($reading->index)->toBeNull()
        ->and($reading->start)->toBeNull()
        ->and($reading->time)->toBe($time);
})->with(['00:00', '25:00', '01:15', '', 'garbage']);

it('parses quarter-hour labels when quarter-hourly data was requested', function () use ($madrid) {
    $reading = ConsumptionReading::fromRow(['date' => '2026/01/01', 'time' => '01:15', 'consumptionKWh' => 0.05], $madrid, MeasurementType::QuarterHourly);

    expect($reading->index)->toBe(4)
        ->and($reading->start?->format('H:i'))->toBe('01:00')
        ->and($reading->end?->format('H:i'))->toBe('01:15');
});

it('tells the hour of the day of hourly and quarter-hourly rows, for the tariff period', function (string $time, MeasurementType $type, ?int $hour) use ($madrid) {
    $reading = ConsumptionReading::fromRow(['date' => '2026/01/01', 'time' => $time, 'consumptionKWh' => 0.05], $madrid, $type);

    expect($reading->hourOfDay)->toBe($hour);
})->with([
    'first hour' => ['01:00', MeasurementType::Hourly, 0],
    'last hour' => ['24:00', MeasurementType::Hourly, 23],
    'first quarter' => ['00:15', MeasurementType::QuarterHourly, 0],
    'last quarter of an hour' => ['11:00', MeasurementType::QuarterHourly, 10],
    'first quarter of the next hour' => ['11:15', MeasurementType::QuarterHourly, 11],
    'last quarter of the day' => ['24:00', MeasurementType::QuarterHourly, 23],
    'unrecognised label' => ['00:00', MeasurementType::Hourly, null],
]);

it('drops rows without a usable consumption or date', function (array $row) use ($madrid, $hourly) {
    expect(ConsumptionReading::fromRow($row, $madrid, $hourly))->toBeNull();
})->with([
    'null consumption' => [['date' => '2026/01/01', 'time' => '01:00', 'consumptionKWh' => null]],
    'missing consumption' => [['date' => '2026/01/01', 'time' => '01:00']],
    'text consumption' => [['date' => '2026/01/01', 'time' => '01:00', 'consumptionKWh' => 'lots']],
    'bad date' => [['date' => '2026/02/30', 'time' => '01:00', 'consumptionKWh' => 1]],
    'missing date' => [['time' => '01:00', 'consumptionKWh' => 1]],
    'missing time' => [['date' => '2026/01/01', 'consumptionKWh' => 1]],
]);

it('coerces numeric strings and keeps an empty obtain method as an empty string', function () use ($madrid, $hourly) {
    $reading = ConsumptionReading::fromRow(['date' => '2026/01/01', 'time' => '01:00', 'consumptionKWh' => '0.5', 'obtainMethod' => ''], $madrid, $hourly);

    expect($reading->kWh)->toBe('0.500')->and($reading->obtainMethod)->toBe('')->and($reading->isReal())->toBeFalse();
});

it('renders float noise and exponent notation as exact decimals', function () use ($madrid, $hourly) {
    $reading = ConsumptionReading::fromRow(['date' => '2026/01/01', 'time' => '01:00', 'consumptionKWh' => 1.0E-5], $madrid, $hourly);

    expect($reading->kWh)->toBe('0.000');
});

it('keeps the 25 hour day in source order with the repeated label', function () use ($madrid, $hourly) {
    $rows = Payloads::hourlyRows('2025/10/26', Payloads::autumnDay());
    $readings = array_map(fn (array $row) => ConsumptionReading::fromRow($row, $madrid, $hourly), $rows);

    expect($readings)->toHaveCount(25)
        ->and(array_map(fn ($r) => $r->time, array_slice($readings, 2, 3)))->toBe(['03:00', '03:00', '04:00'])
        ->and($readings[2]->kWh)->not->toBe($readings[3]->kWh)
        ->and($readings[2]->index)->toBe(2)->and($readings[3]->index)->toBe(2);
});

it('reads the obtain method with spaces and knows every spelling of an estimate', function (string $method, bool $real, bool $estimated) {
    $reading = ConsumptionReading::fromRow(['date' => '2026/01/01', 'time' => '01:00', 'consumptionKWh' => 1, 'obtainMethod' => $method], new DateTimeZone('Europe/Madrid'), MeasurementType::Hourly);

    expect($reading->isReal())->toBe($real)->and($reading->isEstimated())->toBe($estimated);
})->with([[' Real ', true, false], ['Estimada', false, true], ['Estimated', false, true], ['estimate', false, true], ['', false, false]]);

it('reads the self-consumption values with three decimals', function () {
    $reading = ConsumptionReading::fromRow(['date' => '2026/01/01', 'time' => '01:00', 'consumptionKWh' => 1, 'generationEnergyKWh' => 0.12345, 'selfConsumptionEnergyKWh' => 2], new DateTimeZone('Europe/Madrid'), MeasurementType::Hourly);

    expect($reading->generationKWh)->toBe('0.123')->and($reading->selfConsumptionKWh)->toBe('2.000');
});
