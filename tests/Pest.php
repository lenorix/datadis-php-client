<?php

declare(strict_types=1);

use Eris\TestTrait;
use Lenorix\DatadisClient\ApiVersion;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Guard\RequestFingerprinter;
use Lenorix\DatadisClient\Guard\RequestLedger;
use Lenorix\DatadisClient\Http\RetryingClient;
use Lenorix\DatadisClient\Support\InMemoryCache;
use Lenorix\DatadisClient\Tests\Support\FakeHttpClient;
use Lenorix\DatadisClient\Tests\Support\FrozenClock;

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
    $clock = new FrozenClock(new DateTimeImmutable('2026-09-15 10:00:00', new DateTimeZone('Europe/Madrid')));
    $client = new DatadisClient(
        new DatadisConfig('A00000000', 'secret', baseUrl: 'https://datadis.test'),
        http: $retries ? new RetryingClient($http, sleep: static function (int $ms): void {}) : $http,
        version: $version,
        clock: $clock,
        ledger: $ledger ? new RequestLedger(new InMemoryCache($clock), new RequestFingerprinter('a-secret-key-of-at-least-32-bytes!!'), $clock) : null,
    );

    return [$client, $http, $clock];
}
