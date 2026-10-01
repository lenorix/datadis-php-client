<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Values\Nif;

it('normalises to trimmed uppercase', function () {
    expect(Nif::fromString(' a00000000 ')->value())->toBe('A00000000')
        ->and('NIF '.Nif::fromString(' a00000000 '))->toBe('NIF A00000000');
});

it('accepts a NIF, NIE or CIF whose control character matches', function (string $value) {
    expect(Nif::isValid($value))->toBeTrue()->and(Nif::fromString($value)->value())->toBe(strtoupper($value));
})->with(['00000000T', '00000000t', 'X0000000T', 'A00000000', 'Q0000000J', 'C00000000', 'C0000000J']);

it('refuses one whose control character does not match, before anything is sent', function (string $value) {
    expect(Nif::isValid($value))->toBeFalse()
        ->and(fn () => Nif::fromString($value))->toThrow(InvalidArgumentException::class);
})->with([
    'NIF with a mistyped letter' => ['00000000A'],
    'NIE with a mistyped letter' => ['X0000000A'],
    'CIF with the wrong control letter' => ['A0000000A'],
    'CIF of a company with a letter, which takes a digit' => ['A0000000J'],
    'CIF of a public body with a digit, which takes a letter' => ['Q00000000'],
]);

it('requires the kind of control character the first letter of a CIF calls for', function (string $first, string $kind) {
    // 0000000 sums to 0, whose control is the digit 0 or the letter J.
    expect([Nif::isValid("{$first}00000000"), Nif::isValid("{$first}0000000J")])->toBe(match ($kind) {
        'digit' => [true, false],
        'letter' => [false, true],
        'either' => [true, true],
    });
})->with([
    ['A', 'digit'], ['B', 'digit'], ['E', 'digit'], ['H', 'digit'],
    ['P', 'letter'], ['Q', 'letter'], ['S', 'letter'],
    ['C', 'either'], ['D', 'either'], ['F', 'either'], ['G', 'either'], ['J', 'either'], ['N', 'either'], ['R', 'either'], ['U', 'either'], ['V', 'either'], ['W', 'either'],
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

it('keeps its value out of var_dump, print_r, debug_zval_dump and var_export', function () {
    $nif = Nif::fromString('00000000T');

    ob_start();
    var_dump($nif);
    debug_zval_dump($nif);

    expect((string) ob_get_clean().print_r($nif, true).var_export($nif, true))->not->toContain('00000000T')->toContain('[hidden]');
});

it('keeps its value through serialize, which stores it on purpose', function () {
    $nif = unserialize(serialize(Nif::fromString('x0000000t')));

    expect($nif)->toBeInstanceOf(Nif::class)
        ->and($nif->value())->toBe('X0000000T')
        ->and($nif->equals(Nif::fromString('X0000000T')))->toBeTrue();
});

it('refuses to unserialize a value that is not a NIF, NIE or CIF', function (string $tampered) {
    unserialize(str_replace('s:5:"value";s:9:"00000000T";', $tampered, serialize(Nif::fromString('00000000T'))));
})->with([
    'not a NIF' => ['s:5:"value";s:9:"AAAAAAAAA";'],
    'not a string' => ['s:5:"value";i:1;'],
    'no value' => ['s:5:"other";s:9:"00000000T";'],
])->throws(InvalidArgumentException::class);
