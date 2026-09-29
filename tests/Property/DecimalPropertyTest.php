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
