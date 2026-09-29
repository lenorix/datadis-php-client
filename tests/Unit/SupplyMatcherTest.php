<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Data\Supply;
use Lenorix\DatadisClient\SupplyMatcher;
use Lenorix\DatadisClient\Values\Cups;

$zone = new DateTimeZone('Europe/Madrid');
$supply = fn (array $row) => Supply::fromRow($row + ['distributorCode' => '2', 'pointType' => 5], $zone);

it('matches on the first 20 characters whatever the frontier suffix', function () use ($supply) {
    $supplies = [$supply(['cups' => 'ES0031300000000001JN0F', 'validDateFrom' => '2022/01/01', 'validDateTo' => ''])];

    expect(SupplyMatcher::pick($supplies, Cups::fromString('ES0031300000000001JN'))?->cups)->toBe('ES0031300000000001JN0F')
        ->and(SupplyMatcher::pick($supplies, Cups::fromString('ES0031300000000001JN0F'))?->cups)->toBe('ES0031300000000001JN0F');
});

it('returns null when nothing matches', function () use ($supply) {
    expect(SupplyMatcher::pick([$supply(['cups' => 'ES0031300000000002JN'])], Cups::fromString('ES0031300000000001JN')))->toBeNull()
        ->and(SupplyMatcher::pick([], Cups::fromString('ES0031300000000001JN')))->toBeNull();
});

it('prefers the open-ended contract among several rows for the same CUPS', function () use ($supply) {
    $supplies = [
        $supply(['cups' => 'ES0031300000000001JN', 'validDateFrom' => '2015/01/01', 'validDateTo' => '2019/12/31', 'distributorCode' => '1']),
        $supply(['cups' => 'ES0031300000000001JN', 'validDateFrom' => '2020/01/01', 'validDateTo' => '', 'distributorCode' => '2']),
        $supply(['cups' => 'ES0031300000000001JN', 'validDateFrom' => '2010/01/01', 'validDateTo' => '2014/12/31', 'distributorCode' => '3']),
    ];

    expect(SupplyMatcher::pick($supplies, Cups::fromString('ES0031300000000001JN'))?->distributorCode)->toBe('2');
});

it('otherwise picks the contract that started last, comparing real dates', function () use ($supply) {
    $supplies = [
        $supply(['cups' => 'ES0031300000000001JN', 'validDateFrom' => '2015/01/01', 'validDateTo' => '2019/12/31', 'distributorCode' => '1']),
        $supply(['cups' => 'ES0031300000000001JN', 'validDateFrom' => '2019/01/01', 'validDateTo' => '2021/06/30', 'distributorCode' => '2']),
        $supply(['cups' => 'ES0031300000000001JN', 'validDateFrom' => 'not a date', 'validDateTo' => '2021/06/30', 'distributorCode' => '3']),
    ];

    expect(SupplyMatcher::pick($supplies, Cups::fromString('ES0031300000000001JN'))?->distributorCode)->toBe('2');
});

it('keeps the first of several open-ended rows', function () use ($supply) {
    $supplies = [
        $supply(['cups' => 'ES0031300000000001JN', 'validDateFrom' => '2020/01/01', 'distributorCode' => '4']),
        $supply(['cups' => 'ES0031300000000001JN', 'validDateFrom' => '2020/01/01', 'distributorCode' => '5']),
    ];

    expect(SupplyMatcher::pick($supplies, Cups::fromString('ES0031300000000001JN'))?->distributorCode)->toBe('4');
});

it('normalises the CUPS of the supplies it compares with', function () use ($supply) {
    expect(SupplyMatcher::pick([$supply(['cups' => ' es0031300000000001jn0f '])], Cups::fromString('ES0031300000000001JN')))->not->toBeNull();
});
