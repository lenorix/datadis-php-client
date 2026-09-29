<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Exceptions\InvalidRequestException;
use Lenorix\DatadisClient\PublicApi\Community;
use Lenorix\DatadisClient\PublicApi\SelfConsumptionSearchQuery;

$from = new DateTimeImmutable('2026-01-01');
$to = new DateTimeImmutable('2026-01-31');

it('builds the self-consumption query without a measurement type', function () use ($from, $to) {
    $query = new SelfConsumptionSearchQuery($from, $to, [Community::Canarias], selfConsumptionTypes: ['31', '41'], provinces: ['35', '38'], distributors: ['0031'], sort: ['-sumEnergy']);

    expect($query->toQuery())->toBe([
        'startDate' => '2026/01/01',
        'endDate' => '2026/01/31',
        'page' => 0,
        'pageSize' => 2000,
        'community' => '05',
        'distributor' => '0031',
        'selfConsumption' => '31,41',
        'province' => '35,38',
        'sort' => '-sumEnergy',
    ]);
});

it('refuses unknown self-consumption types and malformed provinces', function (Closure $build) use ($from, $to) {
    $build($from, $to);
})->with([
    'type 30' => [fn ($f, $t) => new SelfConsumptionSearchQuery($f, $t, [Community::Madrid], selfConsumptionTypes: ['30'])],
    'type 75' => [fn ($f, $t) => new SelfConsumptionSearchQuery($f, $t, [Community::Madrid], selfConsumptionTypes: ['75'])],
    'province 3 digits' => [fn ($f, $t) => new SelfConsumptionSearchQuery($f, $t, [Community::Madrid], provinces: ['035'])],
])->throws(InvalidRequestException::class);

it('moves to another page without changing the filters', function () use ($from, $to) {
    $query = new SelfConsumptionSearchQuery($from, $to, [Community::Madrid], selfConsumptionTypes: ['31']);
    $next = $query->withPage(4);

    expect($next->toQuery())->toEqual(['page' => 4] + $query->toQuery())->and($query->page)->toBe(0);
});
