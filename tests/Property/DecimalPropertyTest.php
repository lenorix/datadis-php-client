<?php

declare(strict_types=1);

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Eris\Generators;
use Lenorix\DatadisClient\Support\Decimal;

it('matches an exact decimal conversion of the float shortest representation', function () {
    $this->limitTo(pbtIterations())
        ->forAll(Generators::float(), Generators::choose(0, 6))
        ->then(function (float $value, int $scale) {
            if (! is_finite($value)) {
                return;
            }

            $expected = (string) BigDecimal::of(json_encode($value))->toScale($scale, RoundingMode::HalfUp);

            expect(Decimal::of($value, $scale))->toBe($expected);
        });
});

it('always produces a plain decimal string with the requested scale', function () {
    $this->limitTo(pbtIterations())
        ->forAll(Generators::float(), Generators::choose(0, 6))
        ->then(function (float $value, int $scale) {
            if (! is_finite($value)) {
                return;
            }

            $result = Decimal::of($value, $scale);
            $pattern = $scale === 0 ? '/^-?\d+$/' : '/^-?\d+\.\d{'.$scale.'}$/';

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
                expect($numeric)->toBeTrue()->and($result)->toMatch('/^-?\d+\.\d{3}$/');
            } catch (InvalidArgumentException) {
                expect($numeric)->toBeFalse();
            }
        });
});
