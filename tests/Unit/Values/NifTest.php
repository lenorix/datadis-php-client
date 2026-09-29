<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Values\Nif;

it('normalises to trimmed uppercase', function () {
    expect(Nif::fromString(' 12345678z ')->value())->toBe('12345678Z');
});

it('accepts NIF, NIE and CIF shapes', function (string $value) {
    expect(Nif::isValid($value))->toBeTrue();
})->with(['12345678Z', 'X1234567L', 'y1234567l', 'A12345678', 'B1234567J']);

it('rejects other shapes', function (string $value) {
    Nif::fromString($value);
})->with(['', '1234', '12345678', 'ABCDEFGHI', '12-345678Z', '12345678Z9'])->throws(InvalidArgumentException::class);

it('compares after normalisation', function () {
    expect(Nif::fromString('12345678Z')->sameAs(Nif::fromString(' 12345678z')))->toBeTrue()
        ->and(Nif::fromString('12345678Z')->sameAs(Nif::fromString('87654321X')))->toBeFalse();
});

it('converts to its normalised string', function () {
    expect((string) Nif::fromString(' 12345678z'))->toBe('12345678Z');
});
