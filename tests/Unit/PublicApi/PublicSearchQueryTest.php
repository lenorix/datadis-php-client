<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Exceptions\InvalidRequestException;
use Lenorix\DatadisClient\PublicApi\Community;
use Lenorix\DatadisClient\PublicApi\PublicSearchQuery;

$from = new DateTimeImmutable('2026-01-01');
$to = new DateTimeImmutable('2026-01-31');

it('builds the minimal query', function () use ($from, $to) {
    $query = new PublicSearchQuery($from, $to, [Community::Madrid], ['05']);

    expect($query->toQuery())->toBe([
        'startDate' => '2026/01/01',
        'endDate' => '2026/01/31',
        'page' => 0,
        'pageSize' => 2000,
        'community' => '13',
        'measurementType' => '05',
    ]);
});

it('joins multiple values with commas and keeps every optional filter', function () use ($from, $to) {
    $query = new PublicSearchQuery(
        $from,
        $to,
        [Community::Andalucia, Community::Madrid],
        ['01', '05'],
        page: 3,
        pageSize: 100,
        distributors: ['0172', '0426'],
        fares: ['2T'],
        provinceMunicipalities: ['03133', '04'],
        postalCodes: ['18817'],
        economicSectors: ['1', '4'],
        tensions: ['E0'],
        timeDiscriminations: ['E3'],
        sort: ['-dataDate', 'sumEnergy'],
    );

    expect($query->toQuery())->toBe([
        'startDate' => '2026/01/01',
        'endDate' => '2026/01/31',
        'page' => 3,
        'pageSize' => 100,
        'community' => '01,13',
        'measurementType' => '01,05',
        'distributor' => '0172,0426',
        'fare' => '2T',
        'provinceMunicipality' => '03133,04',
        'postalCode' => '18817',
        'economicSector' => '1,4',
        'tension' => 'E0',
        'timeDiscrimination' => 'E3',
        'sort' => '-dataDate,sumEnergy',
    ]);
});

it('refuses invalid queries', function (Closure $build) use ($from, $to) {
    $build($from, $to);
})->with([
    'no community' => [fn ($f, $t) => new PublicSearchQuery($f, $t, [], ['05'])],
    'three communities' => [fn ($f, $t) => new PublicSearchQuery($f, $t, [Community::Madrid, Community::Ceuta, Community::Melilla], ['05'])],
    'repeated community' => [fn ($f, $t) => new PublicSearchQuery($f, $t, [Community::Madrid, Community::Madrid], ['05'])],
    'measurement type 06' => [fn ($f, $t) => new PublicSearchQuery($f, $t, [Community::Madrid], ['06'])],
    'measurement type 5' => [fn ($f, $t) => new PublicSearchQuery($f, $t, [Community::Madrid], ['5'])],
    'reversed dates' => [fn ($f, $t) => new PublicSearchQuery($t, $f, [Community::Madrid], ['05'])],
    'negative page' => [fn ($f, $t) => new PublicSearchQuery($f, $t, [Community::Madrid], ['05'], page: -1)],
    'page size 0' => [fn ($f, $t) => new PublicSearchQuery($f, $t, [Community::Madrid], ['05'], pageSize: 0)],
    'page size 2001' => [fn ($f, $t) => new PublicSearchQuery($f, $t, [Community::Madrid], ['05'], pageSize: 2001)],
    'economic sector 5' => [fn ($f, $t) => new PublicSearchQuery($f, $t, [Community::Madrid], ['05'], economicSectors: ['5'])],
    'tension E7' => [fn ($f, $t) => new PublicSearchQuery($f, $t, [Community::Madrid], ['05'], tensions: ['E7'])],
    'comma inside a value' => [fn ($f, $t) => new PublicSearchQuery($f, $t, [Community::Madrid], ['05'], postalCodes: ['18817,18800'])],
    'empty value' => [fn ($f, $t) => new PublicSearchQuery($f, $t, [Community::Madrid], ['05'], fares: [''])],
    'unknown sort field' => [fn ($f, $t) => new PublicSearchQuery($f, $t, [Community::Madrid], ['05'], sort: ['cups'])],
])->throws(InvalidRequestException::class);

it('uses the date of the given instant, whatever its time and zone', function () {
    $query = new PublicSearchQuery(
        new DateTimeImmutable('2026-01-01 23:30', new DateTimeZone('Atlantic/Canary')),
        new DateTimeImmutable('2026-01-02 00:10', new DateTimeZone('Europe/Madrid')),
        [Community::Canarias],
        ['05'],
    );

    expect($query->toQuery()['startDate'])->toBe('2026/01/01')->and($query->toQuery()['endDate'])->toBe('2026/01/02');
});

it('moves to another page keeping every other argument', function () use ($from, $to) {
    $query = new PublicSearchQuery(
        $from, $to, [Community::Madrid], ['05'], page: 0, pageSize: 100, distributors: ['0172'], fares: ['2T'],
        provinceMunicipalities: ['04'], postalCodes: ['18817'], economicSectors: ['1'], tensions: ['E0'],
        timeDiscriminations: ['E3'], sort: ['-dataDate'], groupByPostalCode: true,
    );
    $next = $query->withPage(1);

    expect(get_object_vars($next))->toEqual(['page' => 1] + get_object_vars($query))
        ->and($next->toQuery())->toEqual(['page' => 1] + $query->toQuery())
        ->and($query->page)->toBe(0);
});

it('accepts a single day and refuses a doubled minus in sort', function () use ($from) {
    expect((new PublicSearchQuery($from, $from, [Community::Madrid], ['05']))->toQuery()['endDate'])->toBe('2026/01/01')
        ->and(fn () => new PublicSearchQuery($from, $from, [Community::Madrid], ['05'], sort: ['--dataDate']))->toThrow(InvalidRequestException::class);
});

it('does not require a measurement type and can group by postal code', function () use ($from, $to) {
    $query = new PublicSearchQuery($from, $to, [Community::Madrid], groupByPostalCode: true);

    expect($query->toQuery())->not->toHaveKey('measurementType')
        ->and($query->toQuery()['groupByPostalCode'])->toBe(1)
        ->and((new PublicSearchQuery($from, $to, [Community::Madrid], groupByPostalCode: false))->toQuery()['groupByPostalCode'])->toBe(0);
});

it('leaves paging out of the sum query', function () use ($from, $to) {
    $query = new PublicSearchQuery($from, $to, [Community::Madrid], ['05'], page: 3, sort: ['-sumEnergy']);

    expect($query->toSumQuery())->not->toHaveKey('page')->not->toHaveKey('pageSize')
        ->and($query->toSumQuery()['sort'])->toBe('-sumEnergy');
});

it('accepts the date fields of the answers as sort fields', function () use ($from, $to) {
    expect((new PublicSearchQuery($from, $to, [Community::Madrid], sort: ['dataYear', '-dataMonth', 'dataDay']))->toQuery()['sort'])->toBe('dataYear,-dataMonth,dataDay');
});
