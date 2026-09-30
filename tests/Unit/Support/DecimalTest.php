<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Support\Decimal;

it('keeps every digit Datadis sends and pads to a minimum number of decimals', function (int|float|string $input, int $minScale, string $expected) {
    expect(Decimal::of($input, $minScale))->toBe($expected);
})->with([
    'int' => [3, 3, '3.000'],
    'float' => [0.323, 3, '0.323'],
    'more decimals than the minimum' => [0.0005, 3, '0.0005'],
    'a standard contracted power' => [1.725, 2, '1.725'],
    'numeric string' => ['2.5', 2, '2.50'],
    'trailing zeros beyond the minimum carry nothing' => ['2.50000', 2, '2.50'],
    'a whole number written with decimals' => ['2.000', 0, '2'],
    'negative' => [-1.2345, 3, '-1.2345'],
    'exponent notation' => [1.0E-5, 3, '0.00001'],
    'large exponent' => [1.0E+15, 0, '1000000000000000'],
    'negative zero' => [-0.0, 2, '0.00'],
]);

it('rejects values that are not finite numbers', function (mixed $input) {
    Decimal::of($input, 3);
})->with([[NAN], [INF], [-INF], ['abc'], [''], ['1,5'], [true], [null], [[]]])->throws(InvalidArgumentException::class);

it('refuses numeric strings that would take huge time or memory', function (string $input) {
    expect(Decimal::isNumeric($input))->toBeFalse();
    Decimal::of($input, 3);
})->with([
    'huge exponent' => ['1e99999999999999999999'],
    'four digit exponent' => ['1e1000'],
    'long digits' => [str_repeat('9', 65)],
])->throws(InvalidArgumentException::class);

it('still accepts every exponent a float can have', function () {
    expect(Decimal::of('1e308', 0))->toStartWith('1000')
        ->and(Decimal::of('-1.5E-10', 3))->toBe('-0.00000000015')
        ->and(Decimal::of(PHP_FLOAT_MAX, 0))->toStartWith('1797');
});

it('accepts a numeric string of exactly the maximum length', function () {
    expect(Decimal::isNumeric(str_repeat('9', 64)))->toBeTrue()->and(Decimal::of(0, 0))->toBe('0');
});

it('writes the same digits whatever the serialize_precision setting', function () {
    $previous = ini_set('serialize_precision', '17');

    try {
        expect(Decimal::of(1.0005, 3))->toBe('1.0005')->and(Decimal::of(0.1, 1))->toBe('0.1')
            ->and(ini_get('serialize_precision'))->toBe('17');
    } finally {
        ini_set('serialize_precision', (string) $previous);
    }
});
