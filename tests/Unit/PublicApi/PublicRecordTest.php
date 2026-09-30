<?php

declare(strict_types=1);

use Lenorix\DatadisClient\PublicApi\PublicRecord;

$row = fn () => json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/public/search.json'), true)[0];

it('exposes the documented aggregate fields', function () use ($row) {
    $record = PublicRecord::fromRow($row());

    expect($record->text('community'))->toBe('Andalucía')
        ->and($record->date()?->format('Y-m-d'))->toBe('2022-04-16')
        ->and($record->decimal('sumEnergy'))->toBe('30300495.000')
        ->and($record->sumContracts())->toBe(5062835)
        ->and($record->text('missing'))->toBeNull()
        ->and($record->raw)->toBe($row());
});

it('exposes the 25 hourly buckets, the 25th being the extra hour of the autumn change', function () use ($row) {
    $hours = PublicRecord::fromRow($row())->hourly();

    expect($hours)->toHaveCount(25)
        ->and($hours[1])->toBe('3140388.000')
        ->and($hours[24])->toBe('3536777.000')
        ->and($hours[25])->toBe('3.000')
        ->and(array_keys($hours))->toBe(range(1, 25));
});

it('returns null buckets when there are none', function () {
    expect(PublicRecord::fromRow(['x' => 1])->hourly())->toBe(array_fill(1, 25, null));
});

it('reads an absurd number as missing instead of failing', function () {
    $record = PublicRecord::fromRow(['sumEnergy' => '1e99999999999999999999', 'mi1' => '1e1000']);

    expect($record->decimal('sumEnergy'))->toBeNull()->and($record->hourly()[1])->toBeNull();
});

it('reads the day, the energy, the power and the contracts in every documented spelling', function () {
    $search = PublicRecord::fromRow(json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/public/search-auto.json'), true)[0]);
    $sum = PublicRecord::fromRow(json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/public/sum-search-auto.json'), true)[0]);

    expect($search->date()?->format('Y-m-d'))->toBe('2022-04-25')
        ->and($search->sumEnergy())->toBe('4332298.000')
        ->and($search->sumPower())->toBe('121843.000')
        ->and($search->sumContracts())->toBe(1609)
        ->and($sum->date())->toBeNull()
        ->and($sum->sumEnergy())->toBe('55304627.000')
        ->and($sum->sumContracts())->toBe(16577);
});

it('has no date when the parts are missing or impossible', function (array $row) {
    expect(PublicRecord::fromRow($row)->date())->toBeNull();
})->with([[['dataDay' => 31, 'dataMonth' => 2, 'dataYear' => 2022]], [['dataDay' => 1, 'dataMonth' => 1]], [['dataDay' => 'x', 'dataMonth' => 1, 'dataYear' => 2022]]]);
