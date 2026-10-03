<?php

declare(strict_types=1);

use Eris\TestTrait;
use Lenorix\DatadisClient\ApiVersion;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Http\RetryingClient;
use Lenorix\DatadisClient\Tests\Support\FakeHttpClient;
use Lenorix\DatadisClient\Tests\Support\FrozenClock;
use Lenorix\DatadisClient\Tests\Support\Scenario;

uses(TestTrait::class)->in('Property');

// A request the fake HTTP client did not expect fails the test even when the code under test
// swallows the exception (it turns client failures into its own exceptions on purpose).
uses()->afterEach(function () {
    $unexpected = FakeHttpClient::takeUnexpected();

    expect($unexpected)->toBe([], 'The fake HTTP client got requests nothing was queued for.');
})->in(__DIR__);

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

/** @return array{DatadisClient, FakeHttpClient, FrozenClock} */
function flowClient(ApiVersion $version = ApiVersion::V2, bool $ledger = false, bool $retries = false): array
{
    $http = new FakeHttpClient;
    $clock = Scenario::clock();
    $client = Scenario::client($retries ? new RetryingClient($http, sleep: static function (int $ms): void {}) : $http, $clock, $ledger ? Scenario::ledger($clock) : null, $version);

    return [$client, $http, $clock];
}
