<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Tariff\AccessFareParser;
use Lenorix\DatadisClient\Tariff\AccessTariff;

it('recognises the tariff by the shape of the description', function (string $accessFare, ?AccessTariff $expected) {
    expect(AccessFareParser::parse($accessFare))->toBe($expected);
})->with([
    'real 2.0TD' => ['BAJA TENSION y POTENCIA <= 15 kW', AccessTariff::T20TD],
    'real alias' => ['2.0TD PEAJE ATR', AccessTariff::T20TD],
    'real 3.0TD with a double space' => ['BAJA TENSION Y POTENCIA  > 15 kW', AccessTariff::T30TD],
    'accented and symbol' => ['Baja tensión y potencia ≤ 15 kW', AccessTariff::T20TD],
    'greater or equal symbol' => ['Tensión ≥ 1 kV y < 30 kV', AccessTariff::T61TD],
    'words for 2.0TD' => ['Baja tension, potencia menor o igual a 15 kW', AccessTariff::T20TD],
    'words for 3.0TD' => ['Baja tension, potencia superior a 15 kW', AccessTariff::T30TD],
    '6.1TD' => ['>= 1 kV y < 30 kV', AccessTariff::T61TD],
    '6.2TD' => ['>= 30 kV y < 72,5 kV', AccessTariff::T62TD],
    '6.3TD from the manual' => ['>= 72.5 kV y < 145 kV', AccessTariff::T63TD],
    '6.4TD' => ['>= 145 kV', AccessTariff::T64TD],
    'lower bound words' => ['Tension mayor o igual a 30 kV', AccessTariff::T62TD],
    'alias with a space' => ['PEAJE 3.0 TD', AccessTariff::T30TD],
    'alias 6.4' => ['6.4TD', AccessTariff::T64TD],
    'band and alias agree' => ['2.0TD BAJA TENSION y POTENCIA <= 15 kW', AccessTariff::T20TD],
    'band and alias disagree' => ['2.0TD BAJA TENSION Y POTENCIA > 15 kW', null],
    'unknown alias' => ['9.9TD', null],
    'unknown text' => ['TARIFA RARA', null],
    'baja tension without a power band' => ['Baja tensión', null],
    'empty' => ['', null],
]);
