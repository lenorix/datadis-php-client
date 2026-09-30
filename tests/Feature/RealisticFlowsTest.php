<?php

declare(strict_types=1);

use Lenorix\DatadisClient\ApiVersion;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Exceptions\AuthenticationException;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\DatadisClient\Exceptions\ServiceUnavailableException;
use Lenorix\DatadisClient\Guard\RequestFingerprinter;
use Lenorix\DatadisClient\Guard\RequestLedger;
use Lenorix\DatadisClient\Http\RetryingClient;
use Lenorix\DatadisClient\Support\InMemoryCache;
use Lenorix\DatadisClient\Tests\Support\FakeHttpClient;
use Lenorix\DatadisClient\Tests\Support\FrozenClock;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Lenorix\DatadisClient\Tests\Support\Scenario;
use Lenorix\DatadisClient\Tests\Support\Tokens;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;
use Psr\Http\Message\ResponseInterface;

/*
 * Whole flows an application goes through, with answers shaped like Datadis's.
 */

/** @return array{DatadisClient, FakeHttpClient, FrozenClock} */
function flowClient(ApiVersion $version = ApiVersion::V2, bool $ledger = false, bool $retries = false): array
{
    $http = new FakeHttpClient;
    $clock = new FrozenClock(new DateTimeImmutable('2026-09-15 10:00:00', new DateTimeZone('Europe/Madrid')));
    $client = new DatadisClient(
        new DatadisConfig('12345678Z', 'secret', baseUrl: 'https://datadis.test'),
        http: $retries ? new RetryingClient($http, sleep: static function (int $ms): void {}) : $http,
        version: $version,
        clock: $clock,
        ledger: $ledger ? new RequestLedger(new InMemoryCache($clock), new RequestFingerprinter('a-secret-key-of-at-least-32-bytes!!'), $clock) : null,
    );

    return [$client, $http, $clock];
}

function refusedTokenAnswer(): ResponseInterface
{
    return Responses::datadisError(datadisFixture('errors/401-spring.json'), 401);
}

it('finds a supply by the CUPS printed on an invoice and queries it with the CUPS Datadis listed', function () {
    [$client, $http, $clock] = flowClient(ApiVersion::V1);
    $http->queue(
        Responses::text(Tokens::datadis($clock->now()->getTimestamp())),
        Responses::datadis(datadisFixture('v1/supplies-authorized.json')),
        Responses::datadis('[]'),
    );

    // Invoices often print the 20 character form; Datadis refuses it on data calls (verified).
    $supply = $client->findSupply(Cups::fromString('es0031300000000001jn'));
    $client->consumptionOf($supply, Month::of(2026, 7));

    parse_str($http->requests()[2]->getUri()->getQuery(), $query);

    expect($query['cups'])->toBe('ES0031300000000001JN0F')
        ->and($query['distributorCode'])->toBe('2')
        ->and($query['pointType'])->toBe('5');
});

it('keeps using the token for its 24 hours and logs in again just before it expires', function () {
    [$client, $http, $clock] = flowClient();
    $http->queue(
        Responses::text(Tokens::datadis($clock->now()->getTimestamp())),
        Responses::datadis('{"supplies":[],"distributorError":[]}'),
        Responses::datadis('{"supplies":[],"distributorError":[]}'),
        Responses::text(Tokens::datadis($clock->now()->getTimestamp() + 86400)),
        Responses::datadis('{"supplies":[],"distributorError":[]}'),
    );

    $client->supplies();
    $clock->advance(86400 - 121);
    $client->supplies();
    $clock->advance(2);
    $client->supplies();

    $paths = array_map(fn ($r) => $r->getUri()->getPath(), $http->requests());

    expect($paths)->toBe([
        '/nikola-auth/tokens/login',
        '/api-private/api/get-supplies-v2',
        '/api-private/api/get-supplies-v2',
        '/nikola-auth/tokens/login',
        '/api-private/api/get-supplies-v2',
    ]);
});

it('counts a query answered after a refused token and a new login against the 24 hour rule', function (ApiVersion $version) {
    [$client, $http, $clock] = flowClient($version, ledger: true);
    $http->queue(
        Responses::text(Tokens::datadis($clock->now()->getTimestamp())),
        refusedTokenAnswer(),
        Responses::text(Tokens::datadis($clock->now()->getTimestamp())),
        Responses::datadis($version === ApiVersion::V1 ? '[]' : '{"timeCurve":[],"distributorError":[]}'),
    );
    $query = fn () => $client->consumption(Cups::fromString(Scenario::CUPS), '2', 5, Month::of(2026, 7));

    $query();

    expect($query)->toThrow(RepetitionWindowException::class)
        ->and($http->requests())->toHaveCount(4);
})->with([ApiVersion::V1, ApiVersion::V2]);

it('counts a query whose new login failed after a refused token, since the query was sent', function () {
    [$client, $http, $clock] = flowClient(ledger: true);
    $http->queue(
        Responses::text(Tokens::datadis($clock->now()->getTimestamp())),
        refusedTokenAnswer(),
        Responses::datadisError('bad credentials', 401),
    );
    $query = fn () => $client->consumption(Cups::fromString(Scenario::CUPS), '2', 5, Month::of(2026, 7));

    expect($query)->toThrow(AuthenticationException::class)
        ->and($query)->toThrow(RepetitionWindowException::class)
        ->and($http->requests())->toHaveCount(3);
});

it('retries through the client only the calls that are safe to repeat', function (Closure $call, array $answers, int $requests, ?string $failure) {
    [$client, $http, $clock] = flowClient(retries: true);
    $http->queue(Responses::text(Tokens::datadis($clock->now()->getTimestamp())), ...$answers);

    $failure === null ? $call($client) : expect(fn () => $call($client))->toThrow($failure);

    expect($http->requests())->toHaveCount($requests);
})->with([
    'supplies after a gateway error' => [
        fn (DatadisClient $c) => $c->supplies(),
        [Responses::empty(503), Responses::datadis('{"supplies":[],"distributorError":[]}')],
        3,
        null,
    ],
    'contract detail after the empty 500 of a missing parameter' => [
        fn (DatadisClient $c) => $c->contractDetail(Cups::fromString(Scenario::CUPS), '2'),
        [Responses::empty(500)],
        2,
        ServiceUnavailableException::class,
    ],
    'consumption after a gateway error' => [
        fn (DatadisClient $c) => $c->consumption(Cups::fromString(Scenario::CUPS), '2', 5, Month::of(2026, 7)),
        [Responses::empty(503)],
        2,
        ServiceUnavailableException::class,
    ],
]);
