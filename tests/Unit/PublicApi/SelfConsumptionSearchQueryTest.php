<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Exceptions\InvalidRequestException;
use Lenorix\DatadisClient\PublicApi\Community;
use Lenorix\DatadisClient\PublicApi\SelfConsumptionSearchQuery;

$from = new DateTimeImmutable('2026-01-01');
$to = new DateTimeImmutable('2026-01-31');

it('builds the self-consumption query without a measurement type', function () use ($from, $to) {
    $query = new SelfConsumptionSearchQuery($from, $to, [Community::Canarias], selfConsumption: ['31', '41'], province: ['35', '38'], distributor: ['0031'], sort: ['-sumEnergy']);

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

it('refuses unknown self-consumption types and malformed province', function (Closure $build) use ($from, $to) {
    $build($from, $to);
})->with([
    'type 30' => [fn ($f, $t) => new SelfConsumptionSearchQuery($f, $t, [Community::Madrid], selfConsumption: ['30'])],
    'type 75' => [fn ($f, $t) => new SelfConsumptionSearchQuery($f, $t, [Community::Madrid], selfConsumption: ['75'])],
    'province 3 digits' => [fn ($f, $t) => new SelfConsumptionSearchQuery($f, $t, [Community::Madrid], province: ['035'])],
])->throws(InvalidRequestException::class);

it('builds the minimal query without empty filters', function () use ($from, $to) {
    expect((new SelfConsumptionSearchQuery($from, $to, [Community::Madrid]))->toQuery())
        ->toBe(['startDate' => '2026/01/01', 'endDate' => '2026/01/31', 'page' => 0, 'pageSize' => 2000, 'community' => '13']);
});

it('validates dates and paging like the other query', function (Closure $build) use ($from, $to) {
    $build($from, $to);
})->with([
    [fn ($f, $t) => new SelfConsumptionSearchQuery($t, $f, [Community::Madrid])],
    [fn ($f, $t) => new SelfConsumptionSearchQuery($f, $t, [Community::Madrid], page: -1)],
])->throws(InvalidRequestException::class);

it('leaves paging and sorting out of the sum query', function () use ($from, $to) {
    $query = new SelfConsumptionSearchQuery($from, $to, [Community::Madrid], page: 2, sort: ['-sumPower']);

    expect($query->toSumQuery())->toBe(['startDate' => '2026/01/01', 'endDate' => '2026/01/31', 'community' => '13']);
});
