<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Auth\JwtExpiry;
use Lenorix\DatadisClient\Tests\Support\Tokens;

it('reads the exp claim of an unsigned or signed token', function () {
    expect(JwtExpiry::read(Tokens::jwt(['sub' => 'x', 'iat' => 1, 'exp' => 1_800_000_000])))->toBe(1_800_000_000);
});

it('accepts an exp that arrives as a float or a numeric string', function () {
    expect(JwtExpiry::read(Tokens::jwt(['exp' => 1_800_000_000.0])))->toBe(1_800_000_000)
        ->and(JwtExpiry::read(Tokens::jwt(['exp' => '1800000000'])))->toBe(1_800_000_000);
});

it('returns null when there is nothing usable', function (string $token) {
    expect(JwtExpiry::read($token))->toBeNull();
})->with([
    'opaque token' => 'abcdef',
    'two parts' => 'aaa.bbb',
    'payload not base64' => 'aaa.%%%.ccc',
    'payload not json' => 'aaa.'.'bm90IGpzb24'.'.ccc',
    'payload json scalar' => 'aaa.'.'MTIz'.'.ccc',
    'empty' => '',
]);

it('returns null for a missing, non numeric or non positive exp', function (mixed $exp) {
    expect(JwtExpiry::read(Tokens::jwt(['exp' => $exp])))->toBeNull();
})->with([[null], ['soon'], [0], [-5], [true], [[]]]);

it('does not need base64 padding', function () {
    foreach (range(1, 12) as $i) {
        expect(JwtExpiry::read(Tokens::jwt(['exp' => 1_800_000_000, 'pad' => str_repeat('x', $i)])))->toBe(1_800_000_000);
    }
});
