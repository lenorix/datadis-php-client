<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Calendar\Territory;
use Lenorix\DatadisClient\Tariff\AccessTariff;
use Lenorix\DatadisClient\Tariff\FixedSchedulePeriods;
use Lenorix\DatadisClient\Tariff\SixPeriodSchedule;

it('knows the periods of every tariff', function (AccessTariff $tariff, int $energy, int $power) {
    expect($tariff->energyPeriods())->toBe($energy)
        ->and($tariff->powerPeriods())->toBe($power);
})->with([
    [AccessTariff::T20TD, 3, 2],
    [AccessTariff::T30TD, 6, 6],
    [AccessTariff::T61TD, 6, 6],
    [AccessTariff::T62TD, 6, 6],
    [AccessTariff::T63TD, 6, 6],
    [AccessTariff::T64TD, 6, 6],
]);

it('gives the energy period calendar of each tariff for a territory', function (AccessTariff $tariff, string $calendar) {
    expect($tariff->schedule(Territory::Canarias))->toBeInstanceOf($calendar)
        // A working Wednesday of July at noon: 2.0TD peak, and P1 of the Canary high season for the others.
        ->and($tariff->schedule(Territory::Canarias)->periodFor(new DateTimeImmutable('2026-07-08'), 12))->toBe(1);
})->with([
    [AccessTariff::T20TD, FixedSchedulePeriods::class],
    [AccessTariff::T30TD, SixPeriodSchedule::class],
    [AccessTariff::T64TD, SixPeriodSchedule::class],
]);
