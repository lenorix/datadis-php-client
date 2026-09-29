<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Values\Cups;

it('accepts 20 and 22 character CUPS and normalises case and whitespace', function () {
    expect(Cups::fromString('ES0031300000000001JN')->value())->toBe('ES0031300000000001JN')
        ->and(Cups::fromString('  es0031300000000001jn0f ')->value())->toBe('ES0031300000000001JN0F');
});

it('rejects values that are not CUPS shaped', function (string $value) {
    expect(Cups::isValid($value))->toBeFalse();
    Cups::fromString($value);
})->with([
    'too short' => 'ES003130000000000',
    'digits missing' => 'ES003130000000001JN',
    'letters in digits' => 'ES00313000000000A1JN',
    'one trailing char' => 'ES0031300000000001JN0',
    'two trailing letters' => 'ES0031300000000001JNAB',
    'extra invoice chars' => 'ES0031300000000001JN0F123',
    'wrong country' => 'PT0031300000000001JN',
    'empty' => '',
])->throws(InvalidArgumentException::class);

it('exposes the 20 character base and matches on it', function () {
    $long = Cups::fromString('ES0031300000000001JN0F');
    $short = Cups::fromString('ES0031300000000001JN');

    expect($long->base())->toBe('ES0031300000000001JN')
        ->and($short->base())->toBe('ES0031300000000001JN')
        ->and($long->matches($short))->toBeTrue()
        ->and($long->matches(Cups::fromString('ES0031300000000002JN')))->toBeFalse();
});

it('converts to string', function () {
    expect((string) Cups::fromString('es0031300000000001jn'))->toBe('ES0031300000000001JN');
});

it('says a well formed CUPS is valid', function () {
    expect(Cups::isValid(' es0031300000000001jn0f '))->toBeTrue();
});
