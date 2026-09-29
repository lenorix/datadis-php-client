<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Data\MaxPowerReading;

$madrid = new DateTimeZone('Europe/Madrid');

it('decodes a max power reading as an instant', function () use ($madrid) {
    $reading = MaxPowerReading::fromRow(['cups' => 'ES0031300000000001JN0F', 'date' => '2022/01/11', 'time' => '09:45', 'maxPower' => 15.228, 'period' => '1'], $madrid);

    expect($reading->maxPowerKw)->toBe('15.228')
        ->and($reading->instant?->format('Y-m-d H:i'))->toBe('2022-01-11 09:45')
        ->and($reading->period)->toBe('1')
        ->and($reading->periodNumber())->toBe(1);
});

it('understands 24:00 as midnight of the next day', function () use ($madrid) {
    $reading = MaxPowerReading::fromRow(['date' => '2022/12/31', 'time' => '24:00', 'maxPower' => 3.4, 'period' => 'P3'], $madrid);

    expect($reading->instant?->format('Y-m-d H:i'))->toBe('2023-01-01 00:00')->and($reading->periodNumber())->toBe(3);
});

it('normalises the period spellings', function (mixed $period, ?int $number) use ($madrid) {
    $reading = MaxPowerReading::fromRow(['date' => '2022/01/11', 'time' => '09:45', 'maxPower' => 1, 'period' => $period], $madrid);

    expect($reading->periodNumber())->toBe($number);
})->with([
    ['1', 1], ['6', 6], ['P2', 2], ['p5', 5], [4, 4], ['VALLE', null], ['PUNTA', null], ['7', null], ['P0', null], [null, null], ['', null],
]);

it('keeps a reading with an unrecognised time and flags it', function () use ($madrid) {
    $reading = MaxPowerReading::fromRow(['date' => '2022/01/11', 'time' => 'soon', 'maxPower' => 2], $madrid);

    expect($reading->instant)->toBeNull()->and($reading->hasValidTime())->toBeFalse()->and($reading->time)->toBe('soon');
});

it('drops rows without a usable value or date', function (array $row) use ($madrid) {
    expect(MaxPowerReading::fromRow($row, $madrid))->toBeNull();
})->with([
    [['date' => '2022/01/11', 'time' => '09:45']],
    [['date' => '2022/01/11', 'time' => '09:45', 'maxPower' => null]],
    [['date' => '2022/13/11', 'time' => '09:45', 'maxPower' => 1]],
    [['time' => '09:45', 'maxPower' => 1]],
    [['date' => '2022/01/11', 'maxPower' => 1]],
]);
