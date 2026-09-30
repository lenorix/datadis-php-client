<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Tariff\AccessTariff;

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
