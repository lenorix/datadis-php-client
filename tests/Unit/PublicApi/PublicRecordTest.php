<?php

declare(strict_types=1);

use Lenorix\DatadisClient\PublicApi\PublicRecord;

$row = fn () => json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/public/search.json'), true)[0];

it('exposes the documented aggregate fields', function () use ($row) {
    $record = PublicRecord::fromRow($row());

    expect($record->text('community'))->toBe('13')
        ->and($record->text('dataDate'))->toBe('2026/01/01')
        ->and($record->decimal('sumEnergy'))->toBe('1234.567')
        ->and($record->decimal('sumContracts'))->toBe('42.000')
        ->and($record->text('missing'))->toBeNull()
        ->and($record->raw)->toBe($row());
});

it('exposes the 25 hourly buckets, the 25th being the extra hour of the autumn change', function () use ($row) {
    $hours = PublicRecord::fromRow($row())->hourly();

    expect($hours)->toHaveCount(25)
        ->and($hours[1])->toBe('10.500')
        ->and($hours[2])->toBe('9.750')
        ->and($hours[24])->toBe('11.000')
        ->and($hours[25])->toBeNull()
        ->and(array_keys($hours))->toBe(range(1, 25));
});

it('returns null buckets when there are none', function () {
    expect(PublicRecord::fromRow(['x' => 1])->hourly())->toBe(array_fill(1, 25, null));
});

it('reads an absurd number as missing instead of failing', function () {
    $record = PublicRecord::fromRow(['sumEnergy' => '1e99999999999999999999', 'mi1' => '1e1000']);

    expect($record->decimal('sumEnergy'))->toBeNull()->and($record->hourly()[1])->toBeNull();
});
