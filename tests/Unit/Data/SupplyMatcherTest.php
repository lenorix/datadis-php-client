<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Data\Supply;
use Lenorix\DatadisClient\Data\SupplyMatcher;
use Lenorix\DatadisClient\Tests\Support\Scenario;
use Lenorix\DatadisClient\Values\Cups;

$zone = new DateTimeZone('Europe/Madrid');
$supply = fn (array $row) => Supply::fromRow($row + ['distributorCode' => '2', 'pointType' => 5], $zone);

it('matches on the first 20 characters whatever the frontier suffix', function () use ($supply) {
    $supplies = [$supply(['cups' => 'ES0000000000000000AA0A', 'validDateFrom' => '2022/01/01', 'validDateTo' => ''])];

    expect(SupplyMatcher::pick($supplies, Cups::fromString('ES0000000000000000AA'))?->cups)->toBe('ES0000000000000000AA0A')
        ->and(SupplyMatcher::pick($supplies, Cups::fromString('ES0000000000000000AA0A'))?->cups)->toBe('ES0000000000000000AA0A');
});

it('returns null when nothing matches', function () use ($supply) {
    expect(SupplyMatcher::pick([$supply(['cups' => Scenario::otherCups()])], Cups::fromString('ES0000000000000000AA')))->toBeNull()
        ->and(SupplyMatcher::pick([], Cups::fromString('ES0000000000000000AA')))->toBeNull();
});

it('prefers the open-ended contract among several rows for the same CUPS', function () use ($supply) {
    $supplies = [
        $supply(['cups' => 'ES0000000000000000AA', 'validDateFrom' => '2015/01/01', 'validDateTo' => '2019/12/31', 'distributorCode' => '1']),
        $supply(['cups' => 'ES0000000000000000AA', 'validDateFrom' => '2020/01/01', 'validDateTo' => '', 'distributorCode' => '2']),
        $supply(['cups' => 'ES0000000000000000AA', 'validDateFrom' => '2010/01/01', 'validDateTo' => '2014/12/31', 'distributorCode' => '3']),
    ];

    expect(SupplyMatcher::pick($supplies, Cups::fromString('ES0000000000000000AA'))?->distributorCode)->toBe('2');
});

it('otherwise picks the contract that started last, comparing real dates', function () use ($supply) {
    $supplies = [
        $supply(['cups' => 'ES0000000000000000AA', 'validDateFrom' => '2015/01/01', 'validDateTo' => '2019/12/31', 'distributorCode' => '1']),
        $supply(['cups' => 'ES0000000000000000AA', 'validDateFrom' => '2019/01/01', 'validDateTo' => '2021/06/30', 'distributorCode' => '2']),
        $supply(['cups' => 'ES0000000000000000AA', 'validDateFrom' => 'not a date', 'validDateTo' => '2021/06/30', 'distributorCode' => '3']),
    ];

    expect(SupplyMatcher::pick($supplies, Cups::fromString('ES0000000000000000AA'))?->distributorCode)->toBe('2');
});

it('keeps the first of several open-ended rows', function () use ($supply) {
    $supplies = [
        $supply(['cups' => 'ES0000000000000000AA', 'validDateFrom' => '2020/01/01', 'distributorCode' => '4']),
        $supply(['cups' => 'ES0000000000000000AA', 'validDateFrom' => '2020/01/01', 'distributorCode' => '5']),
    ];

    expect(SupplyMatcher::pick($supplies, Cups::fromString('ES0000000000000000AA'))?->distributorCode)->toBe('4');
});

it('normalises the CUPS of the supplies it compares with', function () use ($supply) {
    expect(SupplyMatcher::pick([$supply(['cups' => ' es0000000000000000aa0a '])], Cups::fromString('ES0000000000000000AA')))->not->toBeNull();
});

it('keeps looking after a supply of another CUPS', function () use ($supply) {
    $supplies = [$supply(['cups' => Scenario::otherCups()]), $supply(['cups' => 'ES0000000000000000AA', 'distributorCode' => '7'])];

    expect(SupplyMatcher::pick($supplies, Cups::fromString('ES0000000000000000AA'))?->distributorCode)->toBe('7');
});

it('prefers the open contract even when a closed one started later', function () use ($supply) {
    $supplies = [
        $supply(['cups' => 'ES0000000000000000AA', 'validDateFrom' => '2020/01/01', 'validDateTo' => '', 'distributorCode' => 'open']),
        $supply(['cups' => 'ES0000000000000000AA', 'validDateFrom' => '2022/01/01', 'validDateTo' => '2023/01/01', 'distributorCode' => 'closed']),
    ];

    expect(SupplyMatcher::pick($supplies, Cups::fromString('ES0000000000000000AA'))?->distributorCode)->toBe('open');
});

it('copes with a best candidate that has no start date', function () use ($supply) {
    $supplies = [
        $supply(['cups' => 'ES0000000000000000AA', 'validDateTo' => '2019/01/01', 'distributorCode' => 'undated']),
        $supply(['cups' => 'ES0000000000000000AA', 'validDateFrom' => '2018/01/01', 'validDateTo' => '2019/01/01', 'distributorCode' => 'dated']),
    ];

    expect(SupplyMatcher::pick($supplies, Cups::fromString('ES0000000000000000AA'))?->distributorCode)->toBe('dated');
});
