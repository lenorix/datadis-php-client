<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Support\Decimal;

it('scales numbers with half-up rounding', function (int|float|string $input, int $scale, string $expected) {
    expect(Decimal::of($input, $scale))->toBe($expected);
})->with([
    'int' => [3, 3, '3.000'],
    'float' => [0.323, 3, '0.323'],
    'rounds half up' => [0.0005, 3, '0.001'],
    'rounds down' => [0.0004, 3, '0.000'],
    'numeric string' => ['2.5', 2, '2.50'],
    'negative' => [-1.2345, 3, '-1.235'],
    'exponent notation' => [1.0E-5, 6, '0.000010'],
    'large exponent' => [1.0E+15, 0, '1000000000000000'],
    'float noise' => [0.1 + 0.2, 3, '0.300'],
    'negative zero' => [-0.0, 2, '0.00'],
]);

it('rejects values that are not finite numbers', function (mixed $input) {
    Decimal::of($input, 3);
})->with([[NAN], [INF], [-INF], ['abc'], [''], ['1,5'], [true], [null], [[]]])->throws(InvalidArgumentException::class);
