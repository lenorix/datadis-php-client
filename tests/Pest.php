<?php

declare(strict_types=1);

use Eris\TestTrait;

uses(TestTrait::class)->in('Property');

/**
 * Loads a fixture file from tests/Fixtures. Provenance of each fixture is
 * recorded in tests/Fixtures/README.md.
 */
function datadisFixture(string $name): string
{
    $path = __DIR__.'/Fixtures/'.$name;

    if (! is_file($path)) {
        throw new InvalidArgumentException("Unknown fixture: {$name}");
    }

    return (string) file_get_contents($path);
}

/**
 * Number of iterations for property-based tests. Eris has no environment
 * variable for this (only ERIS_SEED), so we read our own.
 * Use it as: $this->limitTo(pbtIterations())->forAll(...).
 */
function pbtIterations(): int
{
    $value = getenv('DATADIS_PBT_ITERATIONS');

    return $value !== false && ctype_digit($value) && (int) $value > 0 ? (int) $value : 100;
}
