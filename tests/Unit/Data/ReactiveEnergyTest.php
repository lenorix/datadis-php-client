<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Data\ReactiveEnergy;

$payload = fn () => json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/v2/reactive.json'), true)['reactiveEnergy'];

it('decodes reactive energy per period', function () use ($payload) {
    $reactive = ReactiveEnergy::fromRow($payload());

    expect($reactive->cups)->toBe('ES0031300000000001JN0F')
        ->and($reactive->code)->toBe('0')
        ->and($reactive->codeDescription)->toBe('OK')
        ->and($reactive->entries)->toHaveCount(2)
        ->and($reactive->entries[0]->date)->toBe('2025/03')
        ->and($reactive->entries[0]->periods)->toBe([1 => '1.500', 2 => '2.000', 3 => '0.000', 5 => '0.250', 6 => '0.000'])
        ->and($reactive->entries[1]->periods)->toBe([1 => '0.000']);
});

it('returns null for an empty reactive object', function () {
    expect(ReactiveEnergy::fromRow([]))->toBeNull();
});
