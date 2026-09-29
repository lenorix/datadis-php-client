<?php

declare(strict_types=1);

use Eris\Generators;
use Lenorix\DatadisClient\Tests\Support\Gen;
use Lenorix\DatadisClient\Values\Cups;

it('normalises idempotently and keeps a valid shape', function () {
    $this->limitTo(pbtIterations())
        ->forAll(
            Gen::digits(16),
            Gen::letters(2),
            Generators::elements('', '0F', '1p', '2x'),
            Generators::elements('', ' ', "\t", "\n"),
        )
        ->then(function (string $digits, string $letters, string $suffix, string $space) {
            $raw = $space.'es'.$digits.$letters.$suffix.$space;
            $once = Cups::fromString($raw);
            $twice = Cups::fromString($once->value());

            expect($twice->value())->toBe($once->value())
                ->and($once->value())->toBe(strtoupper(trim($raw)))
                ->and($once->base())->toHaveLength(20)
                ->and($once->matches(Cups::fromString('ES'.$digits.strtoupper($letters))))->toBeTrue();
        });
});
