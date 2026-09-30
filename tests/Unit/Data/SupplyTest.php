<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Data\Supply;

$zone = new DateTimeZone('Europe/Madrid');
$rows = fn (string $file) => json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/'.$file), true);

it('decodes a v2 supply', function () use ($zone, $rows) {
    $supply = Supply::fromRow($rows('v2/supplies.json')['supplies'][0], $zone);

    expect($supply->cups)->toBe('ES0031300000000001JN0F')
        ->and($supply->distributorCode)->toBe('2')
        ->and($supply->pointType)->toBe(5)
        ->and($supply->distributor)->toBe('EDISTRIBUCIÓN REDES DIGITALES')
        ->and($supply->validFrom?->format('Y-m-d'))->toBe('2022-01-01')
        ->and($supply->validTo)->toBeNull()
        ->and($supply->isOpenEnded())->toBeTrue()
        ->and($supply->isQueryable())->toBeTrue()
        ->and($supply->raw['address'])->toBe('CALLE EJEMPLO 1');
});

it('decodes a closed contract and a v1 row with code fields', function () use ($zone, $rows) {
    $closed = Supply::fromRow($rows('v2/supplies.json')['supplies'][1], $zone);
    $v1 = Supply::fromRow($rows('v1/supplies-authorized.json')[0], $zone);

    expect($closed->validTo?->format('Y-m-d'))->toBe('2021-12-31')
        ->and($closed->isOpenEnded())->toBeFalse()
        ->and($v1->provinceCode)->toBe('28')
        ->and($v1->municipalityCode)->toBe('079');
});

it('accepts a distributor code and a point type that arrive with another type', function () use ($zone) {
    $supply = Supply::fromRow(['cups' => 'ES0031300000000001JN', 'distributorCode' => 2, 'pointType' => '5'], $zone);

    expect($supply->distributorCode)->toBe('2')->and($supply->pointType)->toBe(5);
});

it('is not queryable when the codes needed by other endpoints are missing', function () use ($zone) {
    $supply = Supply::fromRow(['cups' => 'ES0031300000000001JN'], $zone);

    expect($supply->isQueryable())->toBeFalse()->and($supply->distributorCode)->toBeNull()->and($supply->pointType)->toBeNull();
});

it('rejects a row without a cups', function (array $row) use ($zone) {
    expect(Supply::fromRow($row, $zone))->toBeNull();
})->with([[[]], [['cups' => '']], [['cups' => null]], [['cups' => ['x']]], [['distributorCode' => '2']]]);

it('turns unparseable dates into null while keeping the raw row', function () use ($zone) {
    $supply = Supply::fromRow(['cups' => 'ES0031300000000001JN', 'validDateFrom' => 'yesterday', 'validDateTo' => '2025/02/30'], $zone);

    expect($supply->validFrom)->toBeNull()->and($supply->validTo)->toBeNull()->and($supply->raw['validDateFrom'])->toBe('yesterday');
});

it('needs both codes to be queryable', function (array $row) use ($zone) {
    expect(Supply::fromRow(['cups' => 'ES0031300000000001JN'] + $row, $zone)->isQueryable())->toBeFalse();
})->with([[['distributorCode' => '2']], [['pointType' => 5]]]);
