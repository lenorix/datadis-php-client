<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Data\ContractDetail;
use Lenorix\DatadisClient\Tariff\AccessTariff;

$zone = new DateTimeZone('Europe/Madrid');
$row = fn () => json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/v2/contract-detail.json'), true)['contract'][0];

it('decodes a contract detail', function () use ($zone, $row) {
    $contract = ContractDetail::fromRow($row(), $zone);

    expect($contract->cups)->toBe('ES0031300000000001JN0F')
        ->and($contract->marketer)->toBe('COMERCIALIZADORA EJEMPLO')
        ->and($contract->tension)->toBe('Baja tensión')
        ->and($contract->accessFare)->toBe('BAJA TENSION y POTENCIA <= 15 kW')
        ->and($contract->contractedPowerKw)->toBe(['4.60', '4.60'])
        ->and($contract->modePowerControl)->toBe('ICP')
        ->and($contract->codeFare)->toBe('2T')
        ->and($contract->startDate?->format('Y-m-d'))->toBe('2022-01-01')
        ->and($contract->endDate)->toBeNull()
        ->and($contract->isOpenEnded())->toBeTrue()
        ->and($contract->maxPowerInstall)->toBe('5.500')
        ->and($contract->installedCapacity)->toBe('3.600')
        ->and($contract->partitionCoefficient)->toBe('0.000000')
        ->and($contract->selfConsumptionTypeCode)->toBeNull()
        ->and($contract->lastMarketerDate?->format('Y-m-d'))->toBe('2022-01-01');
});

it('reads the dash dated ownership periods', function () use ($zone, $row) {
    $contract = ContractDetail::fromRow($row(), $zone);

    expect($contract->ownerPeriods)->toHaveCount(1)
        ->and($contract->ownerPeriods[0]['start']?->format('Y-m-d'))->toBe('2022-01-01')
        ->and($contract->ownerPeriods[0]['end'])->toBeNull();
});

it('accepts the alternative spellings seen in the wild', function () use ($zone, $row) {
    $variant = $row();
    unset($variant['accessFare'], $variant['installedCapacityKW']);
    $variant['accesFare'] = 'BAJA TENSION y POTENCIA > 15 kW';
    $variant['installedCapacity'] = 3600;

    $contract = ContractDetail::fromRow($variant, $zone);

    expect($contract->accessFare)->toBe('BAJA TENSION y POTENCIA > 15 kW')->and($contract->installedCapacity)->toBe('3600.000');
});

it('keeps only what it can read from a sparse row', function () use ($zone) {
    $contract = ContractDetail::fromRow(['cups' => 'ES0031300000000001JN', 'contractedPowerkW' => 'n/a', 'endDate' => null, 'dateOwner' => 'x'], $zone);

    expect($contract->contractedPowerKw)->toBe([])
        ->and($contract->ownerPeriods)->toBe([])
        ->and($contract->endDate)->toBeNull()
        ->and($contract->accessFare)->toBeNull()
        ->and($contract->marketer)->toBeNull();
});

it('keeps the position of every contracted power because the position is the period', function () use ($zone) {
    $contract = ContractDetail::fromRow(['cups' => 'ES0031300000000001JN', 'contractedPowerkW' => [4.6, null, 'x', 5]], $zone);

    expect($contract->contractedPowerKw)->toBe(['4.60', null, null, '5.00']);
});

it('rejects a row without a cups', function () use ($zone) {
    expect(ContractDetail::fromRow(['tension' => 'x'], $zone))->toBeNull();
});

it('resolves the access tariff when the description and the number of powers agree', function (string $fare, array $powers, ?AccessTariff $expected) use ($zone) {
    $contract = ContractDetail::fromRow(['cups' => 'ES0031300000000001JN', 'accessFare' => $fare, 'contractedPowerkW' => $powers], $zone);

    expect($contract->tariff())->toBe($expected);
})->with([
    '2.0TD with 2 powers' => ['BAJA TENSION y POTENCIA <= 15 kW', [4.6, 4.6], AccessTariff::T20TD],
    '3.0TD with 6 powers' => ['BAJA TENSION Y POTENCIA  > 15 kW', [20, 20, 20, 20, 20, 25], AccessTariff::T30TD],
    '2.0TD band with 6 powers' => ['BAJA TENSION y POTENCIA <= 15 kW', [1, 1, 1, 1, 1, 1], null],
    '3.0TD with 2 powers' => ['BAJA TENSION Y POTENCIA > 15 kW', [20, 20], null],
    'no powers' => ['BAJA TENSION y POTENCIA <= 15 kW', [], null],
    'unknown description' => ['TARIFA RARA', [4.6, 4.6], null],
]);

it('has no tariff without a description', function () use ($zone) {
    expect(ContractDetail::fromRow(['cups' => 'ES0031300000000001JN', 'contractedPowerkW' => [4.6, 4.6]], $zone)->tariff())->toBeNull();
});

it('reads powers sent as an object, an empty power as missing', function () use ($zone) {
    $contract = ContractDetail::fromRow(['cups' => 'ES0031300000000001JN', 'contractedPowerkW' => ['1' => 4.6, '2' => '']], $zone);

    expect($contract->contractedPowerKw)->toBe(['4.60', null]);
});

it('reads ownership periods with an end, spaces, slashes or no usable date', function () use ($zone) {
    $contract = ContractDetail::fromRow(['cups' => 'ES0031300000000001JN', 'dateOwner' => [
        ['startDate' => ' 2020-01-01 ', 'endDate' => '2021/12/31'],
        ['startDate' => 5, 'endDate' => '  '],
        'not a period',
    ]], $zone);

    expect($contract->ownerPeriods)->toHaveCount(2)
        ->and($contract->ownerPeriods[0]['start']?->format('Y-m-d'))->toBe('2020-01-01')
        ->and($contract->ownerPeriods[0]['end']?->format('Y-m-d'))->toBe('2021-12-31')
        ->and($contract->ownerPeriods[1])->toBe(['start' => null, 'end' => null]);
});
