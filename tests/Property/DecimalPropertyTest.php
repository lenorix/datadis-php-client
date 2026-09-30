<?php

declare(strict_types=1);

use Eris\Generators;
use Lenorix\DatadisClient\Support\Decimal;

/** The decimal text of $units / 10^$decimals, written by hand: an oracle independent of any library. */
function shifted(int $units, int $decimals): string
{
    $digits = str_pad((string) abs($units), $decimals + 1, '0', STR_PAD_LEFT);
    $text = $decimals === 0 ? $digits : substr($digits, 0, -$decimals).'.'.substr($digits, -$decimals);

    return ($units < 0 ? '-' : '').$text;
}

it('keeps the exact value of the numbers Datadis sends, whatever their scale', function () {
    $this->limitTo(pbtIterations())
        ->forAll(Generators::choose(-1_000_000_000, 1_000_000_000), Generators::choose(0, 6))
        ->then(function (int $units, int $decimals) {
            // A reading such as 0.301 kWh arrives as a JSON number, so as a float.
            $value = (float) shifted($units, $decimals);

            expect(Decimal::of($value, $decimals))->toBe(shifted($units, $decimals));
        });
});

it('reads floats that PHP writes in exponent notation', function () {
    $this->limitTo(pbtIterations())
        ->forAll(Generators::choose(1, 999), Generators::oneOf(Generators::choose(-7, -5), Generators::choose(15, 20)))
        ->then(function (int $mantissa, int $exponent) {
            // PHP writes 0.00001 as 1.0e-5 and 1e15 as 1.0e+15; with 9 decimals the value is exact.
            $value = (float) "{$mantissa}e{$exponent}";
            $expected = $exponent < 0
                ? shifted($mantissa * 10 ** (9 + $exponent), 9)
                : $mantissa.str_repeat('0', $exponent).'.000000000';

            expect(Decimal::of($value, 9))->toBe($expected);
        });
});

it('always produces a plain decimal string with at least the requested decimals', function () {
    $this->limitTo(pbtIterations())
        ->forAll(Generators::float(), Generators::choose(0, 6))
        ->then(function (float $value, int $scale) {
            if (! is_finite($value)) {
                return;
            }

            $result = Decimal::of($value, $scale);
            $pattern = $scale === 0 ? '/^-?\d+(\.\d+)?$/' : '/^-?\d+\.\d{'.$scale.',}$/';

            expect($result)->toMatch($pattern);
        });
});

it('never fails in any other way than InvalidArgumentException on arbitrary strings', function () {
    $this->limitTo(pbtIterations())
        ->forAll(Generators::oneOf(
            Generators::string(),
            Generators::map(fn (array $p) => $p[0].'e'.$p[1], Generators::tuple(Generators::choose(-99, 99), Generators::choose(-99999, 99999))),
        ))
        ->then(function (string $value) {
            $numeric = Decimal::isNumeric($value);

            try {
                $result = Decimal::of($value, 3);
                expect($numeric)->toBeTrue()->and($result)->toMatch('/^-?\d+\.\d{3,}$/');
            } catch (InvalidArgumentException) {
                expect($numeric)->toBeFalse();
            }
        });
});
