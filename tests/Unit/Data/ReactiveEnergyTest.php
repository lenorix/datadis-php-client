<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Data\ReactiveEnergy;

$payload = fn () => json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/v2/reactive.json'), true)['reactiveEnergy'];

it('decodes reactive energy per period', function () use ($payload) {
    $reactive = ReactiveEnergy::fromRow($payload());

    expect($reactive->cups)->toBe('ES0000000000000000AA0A')
        ->and($reactive->code)->toBe('0')
        ->and($reactive->codeDescription)->toBe('OK')
        ->and($reactive->energy)->toHaveCount(2)
        ->and($reactive->energy[0]->date)->toBe('2025/03')
        ->and($reactive->energy[0]->periods)->toBe([1 => '1.500', 2 => '2.000', 3 => '0.000', 5 => '0.250', 6 => '0.000'])
        ->and($reactive->energy[1]->periods)->toBe([1 => '0.000']);
});

it('returns null for an empty reactive object', function () {
    expect(ReactiveEnergy::fromRow([]))->toBeNull();
});

it('reads the code description under the key the manual uses too', function () {
    expect(ReactiveEnergy::fromRow(['cups' => 'ES0000000000000000AA0A', 'code_desc' => 'OK'])?->codeDescription)->toBe('OK');
});
