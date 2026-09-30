<?php

declare(strict_types=1);

use Lenorix\DatadisClient\ApiVersion;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Exceptions\AuthenticationException;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\DatadisClient\Exceptions\ServiceUnavailableException;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Lenorix\DatadisClient\Tests\Support\Scenario;
use Lenorix\DatadisClient\Tests\Support\Tokens;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;
use Psr\Http\Message\ResponseInterface;

/*
 * Whole flows an application goes through, with answers shaped like Datadis's.
 */

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
    $client->getConsumptionDataOf($supply, Month::of(2026, 7));

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

    $client->getSupplies();
    $clock->advance(86400 - 121);
    $client->getSupplies();
    $clock->advance(2);
    $client->getSupplies();

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
    $query = fn () => $client->getConsumptionData(Cups::fromString(Scenario::CUPS), '2', 5, Month::of(2026, 7));

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
    $query = fn () => $client->getConsumptionData(Cups::fromString(Scenario::CUPS), '2', 5, Month::of(2026, 7));

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
        fn (DatadisClient $c) => $c->getSupplies(),
        [Responses::empty(503), Responses::datadis('{"supplies":[],"distributorError":[]}')],
        3,
        null,
    ],
    'contract detail after the empty 500 of a missing parameter' => [
        fn (DatadisClient $c) => $c->getContractDetail(Cups::fromString(Scenario::CUPS), '2'),
        [Responses::empty(500)],
        2,
        ServiceUnavailableException::class,
    ],
    'consumption after a gateway error' => [
        fn (DatadisClient $c) => $c->getConsumptionData(Cups::fromString(Scenario::CUPS), '2', 5, Month::of(2026, 7)),
        [Responses::empty(503)],
        2,
        ServiceUnavailableException::class,
    ],
]);
