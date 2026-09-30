<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Values\Nif;

it('normalises to trimmed uppercase', function () {
    expect(Nif::fromString(' 12345678z ')->value())->toBe('12345678Z')
        ->and('NIF '.Nif::fromString(' 12345678z '))->toBe('NIF 12345678Z');
});

it('accepts a NIF, NIE or CIF whose control character matches', function (string $value) {
    expect(Nif::isValid($value))->toBeTrue()->and(Nif::fromString($value)->value())->toBe(strtoupper($value));
})->with(['12345678Z', '87654321X', 'X1234567L', 'y1234567x', 'Z1234567R', 'B12345674', 'Q1234567D', 'A1234567D', 'B12345690', 'Q1234569J']);

it('refuses one whose control character does not match, before anything is sent', function (string $value) {
    expect(Nif::isValid($value))->toBeFalse()
        ->and(fn () => Nif::fromString($value))->toThrow(InvalidArgumentException::class);
})->with([
    'NIF with a mistyped letter' => ['12345678A'],
    'NIF with a mistyped digit' => ['12345679Z'],
    'NIE with the letter of another prefix' => ['Y1234567L'],
    'CIF with the wrong control digit' => ['B12345678'],
    'CIF with the wrong control letter' => ['Q1234567J'],
]);

it('takes one with a mismatching control character when asked not to check it', function () {
    expect(Nif::fromString('12345678A', checkControl: false)->value())->toBe('12345678A')
        ->and(Nif::isValid('12345678A', checkControl: false))->toBeTrue()
        ->and(Nif::isValid('1234', checkControl: false))->toBeFalse();
});

it('rejects other shapes', function (string $value) {
    Nif::fromString($value);
})->with(['', '1234', '12345678', 'ABCDEFGHI', '12-345678Z', '12345678Z9'])->throws(InvalidArgumentException::class);

it('compares after normalisation', function () {
    expect(Nif::fromString('12345678Z')->equals(Nif::fromString(' 12345678z')))->toBeTrue()
        ->and(Nif::fromString('12345678Z')->equals(Nif::fromString('87654321X')))->toBeFalse();
});
