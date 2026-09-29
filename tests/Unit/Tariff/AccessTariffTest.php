<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Tariff\AccessTariff;

it('knows the periods of every tariff', function (AccessTariff $tariff, int $energy, int $power, bool $nonDecreasing) {
    expect($tariff->energyPeriods())->toBe($energy)
        ->and($tariff->powerPeriods())->toBe($power)
        ->and($tariff->requiresNonDecreasingPower())->toBe($nonDecreasing);
})->with([
    [AccessTariff::T20TD, 3, 2, false],
    [AccessTariff::T30TD, 6, 6, true],
    [AccessTariff::T61TD, 6, 6, true],
    [AccessTariff::T62TD, 6, 6, true],
    [AccessTariff::T63TD, 6, 6, true],
    [AccessTariff::T64TD, 6, 6, true],
]);

it('uses the regulatory names as values', function () {
    expect(array_map(fn (AccessTariff $t) => $t->value, AccessTariff::cases()))
        ->toBe(['2.0TD', '3.0TD', '6.1TD', '6.2TD', '6.3TD', '6.4TD']);
});

it('checks a contracted power list against the tariff rules', function (AccessTariff $tariff, array $powers, bool $valid) {
    expect($tariff->acceptsContractedPower($powers))->toBe($valid);
})->with([
    '2.0TD two equal' => [AccessTariff::T20TD, ['4.60', '4.60'], true],
    '2.0TD decreasing is fine' => [AccessTariff::T20TD, ['5.00', '3.00'], true],
    '2.0TD six values' => [AccessTariff::T20TD, ['1', '1', '1', '1', '1', '1'], false],
    '3.0TD non-decreasing' => [AccessTariff::T30TD, ['20', '20', '25', '25', '30', '30'], true],
    '3.0TD decreasing' => [AccessTariff::T30TD, ['50', '50', '45', '45', '40', '40'], false],
    '3.0TD two values' => [AccessTariff::T30TD, ['20', '20'], false],
    'missing value' => [AccessTariff::T20TD, ['4.60', null], false],
    'zero power' => [AccessTariff::T20TD, ['0.00', '4.60'], false],
    'negative power' => [AccessTariff::T20TD, ['-1', '4.60'], false],
    'not a number' => [AccessTariff::T20TD, ['abc', '4.60'], false],
    'empty' => [AccessTariff::T20TD, [], false],
]);
