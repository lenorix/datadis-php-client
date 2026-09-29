<?php

declare(strict_types=1);

use Eris\Generators;
use Lenorix\DatadisClient\Support\PersonalDataRedactor;
use Lenorix\DatadisClient\Tests\Support\Gen;

const CUPS_PATTERN = '/ES\d{16}[A-Z]{2}(\d[A-Z])?/i';
const NIF_PATTERN = '/(?<![A-Z0-9])\d{8}[A-Z](?![A-Z0-9])/i';

it('never leaves an embedded identifier behind', function () {
    $this->limitTo(pbtIterations())
        ->forAll(
            Generators::map(fn (array $parts): string => implode('', $parts), Generators::tuple(Gen::digits(16), Gen::letters(2))),
            Generators::map(fn (array $parts): string => implode('', $parts), Generators::tuple(Gen::digits(8), Gen::letters(1))),
            Generators::elements(' ', ':', '"', "\n", '=', ','),
            Generators::string(),
            Generators::string(),
        )
        ->then(function (string $cupsTail, string $nif, string $separator, string $before, string $after) {
            $cups = 'ES'.$cupsTail;
            $text = $before.$separator.$cups.$separator.$nif.$separator.$after;

            $redacted = PersonalDataRedactor::redact($text);

            expect(preg_match(CUPS_PATTERN, $redacted))->toBe(0)
                ->and(str_contains($redacted, $cups))->toBeFalse();
            // The NIF may only survive if the random noise glued it to alphanumerics, which is not a NIF shape.
            expect(preg_match(NIF_PATTERN, $redacted))->toBe(0);
        });
});

it('is idempotent and total on arbitrary strings', function () {
    $this->limitTo(pbtIterations())
        ->forAll(Generators::string())
        ->then(function (string $text) {
            $once = PersonalDataRedactor::redact($text);

            expect(PersonalDataRedactor::redact($once))->toBe($once)
                ->and(PersonalDataRedactor::excerpt($text, 40))->toBeString();
        });
});

it('caps the excerpt length', function () {
    $this->limitTo(pbtIterations())
        ->forAll(Generators::string(), Generators::choose(0, 120))
        ->then(function (string $text, int $max) {
            expect(mb_strlen(PersonalDataRedactor::excerpt($text, $max)))->toBeLessThanOrEqual($max);
        });
});
