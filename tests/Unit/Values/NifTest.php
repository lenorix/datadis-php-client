<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Values\Nif;

it('normalises to trimmed uppercase', function () {
    expect(Nif::fromString(' a00000000 ')->value())->toBe('A00000000')
        ->and('NIF '.Nif::fromString(' a00000000 '))->toBe('NIF A00000000');
});

it('accepts a NIF, NIE or CIF whose control character matches', function (string $value) {
    expect(Nif::isValid($value))->toBeTrue()->and(Nif::fromString($value)->value())->toBe(strtoupper($value));
})->with(['00000000T', '00000000t', 'X0000000T', 'A00000000', 'A0000000J', 'Q0000000J']);

it('refuses one whose control character does not match, before anything is sent', function (string $value) {
    expect(Nif::isValid($value))->toBeFalse()
        ->and(fn () => Nif::fromString($value))->toThrow(InvalidArgumentException::class);
})->with([
    'NIF with a mistyped letter' => ['00000000A'],
    'NIE with a mistyped letter' => ['X0000000A'],
    'CIF with the wrong control letter' => ['A0000000A'],
]);

it('takes one with a mismatching control character when asked not to check it', function () {
    expect(Nif::fromString('00000000A', checkControl: false)->value())->toBe('00000000A')
        ->and(Nif::isValid('00000000A', checkControl: false))->toBeTrue()
        ->and(Nif::isValid('1234', checkControl: false))->toBeFalse();
});

it('rejects other shapes', function (string $value) {
    Nif::fromString($value);
})->with(['', '0000', '00000000', 'AAAAAAAAA', '00-000000T', '00000000T0'])->throws(InvalidArgumentException::class);

it('compares after normalisation', function () {
    expect(Nif::fromString('A00000000')->equals(Nif::fromString(' a00000000')))->toBeTrue()
        ->and(Nif::fromString('A00000000')->equals(Nif::fromString('00000000T')))->toBeFalse();
});
