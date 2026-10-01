<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Exceptions\AuthenticationException;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Lenorix\DatadisClient\Exceptions\DatadisException;
use Lenorix\DatadisClient\Exceptions\LedgerUnavailableException;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\DatadisClient\Guard\RequestFingerprinter;
use Lenorix\DatadisClient\Guard\RequestLedger;
use Lenorix\DatadisClient\Support\InMemoryCache;
use Lenorix\DatadisClient\Tests\Support\FakeHttpClient;
use Lenorix\DatadisClient\Tests\Support\FrozenClock;
use Lenorix\DatadisClient\Tests\Support\QuirkyCache;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Lenorix\DatadisClient\Tests\Support\Scenario;
use Lenorix\DatadisClient\Tests\Support\Tokens;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\Nif;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** @return array{DatadisClient, FakeHttpClient, FrozenClock} */
function guarded(): array
{
    $http = new FakeHttpClient;
    $clock = new FrozenClock(new DateTimeImmutable('2026-09-15 10:00:00', new DateTimeZone('Europe/Madrid')));
    $ledger = new RequestLedger(new InMemoryCache($clock), new RequestFingerprinter('a-secret-key-of-at-least-32-bytes!!'), $clock);
    $client = new DatadisClient(new DatadisConfig('A00000000', 'secret', baseUrl: 'https://datadis.test'), http: $http, clock: $clock, ledger: $ledger);

    return [$client, $http, $clock];
}

function login(FrozenClock $clock): ResponseInterface
{
    return Responses::text(Tokens::jwt(['exp' => $clock->now()->getTimestamp() + 7 * 86400]));
}

$consumption = fn (DatadisClient $c) => $c->getConsumptionData(Cups::fromString('ES0000000000000000AA0A'), '2', 5, Month::of(2026, 1), Month::of(2026, 1));
$maxPower = fn (DatadisClient $c) => $c->getMaxPower(Cups::fromString('ES0000000000000000AA0A'), '2', Month::of(2026, 1), Month::of(2026, 1));
$reactive = fn (DatadisClient $c) => $c->getReactiveData(Cups::fromString('ES0000000000000000AA0A'), '2', Month::of(2026, 1), Month::of(2026, 1));

it('refuses locally to repeat a guarded query within the window', function () use ($consumption) {
    [$client, $http, $clock] = guarded();
    $http->queue(login($clock), Responses::datadis('{"timeCurve":[],"distributorError":[]}'));

    $consumption($client);

    try {
        $consumption($client);
    } catch (RepetitionWindowException $e) {
        expect($e->requestSent)->toBeFalse()
            ->and($e->httpStatus)->toBeNull()
            ->and($e->endpoint)->toBe('get-consumption-data-v2')
            ->and($http->requests())->toHaveCount(2);

        return;
    }

    throw new LogicException('Expected a RepetitionWindowException.');
});

it('allows the query again once the window is over', function () use ($consumption) {
    [$client, $http, $clock] = guarded();
    $http->queue(login($clock), Responses::datadis('{"timeCurve":[]}'), Responses::datadis('{"timeCurve":[]}'));

    $consumption($client);
    $clock->advance(RequestLedger::WINDOW_SECONDS);
    $consumption($client);

    expect($http->requests())->toHaveCount(3);
});

it('treats max power and reactive with the same window as the same query', function () use ($maxPower, $reactive) {
    [$client, $http, $clock] = guarded();
    $http->queue(login($clock), Responses::datadis('{"maxPower":[]}'));

    $maxPower($client);

    expect(fn () => $reactive($client))->toThrow(RepetitionWindowException::class)
        ->and($http->requests())->toHaveCount(2);
});

it('keeps the attempt after failures that may have reached Datadis', function (Closure $failure) use ($consumption) {
    [$client, $http, $clock] = guarded();
    $http->queue(login($clock), $failure());

    try {
        $consumption($client);
    } catch (DatadisException) {
    }

    expect(fn () => $consumption($client))->toThrow(RepetitionWindowException::class)
        ->and($http->requests())->toHaveCount(2);
})->with([
    'rejected' => [fn () => Responses::text('bad', 400)],
    'server error' => [fn () => Responses::text('', 500)],
    'repetition answered by Datadis' => [fn () => Responses::text('Consulta ya realizada', 429)],
    'network failure' => [fn () => new ConnectException('cURL error 28', new Request('GET', 'https://datadis.test'))],
    'unreadable answer' => [fn () => Responses::datadis('"maintenance"')],
    'no data' => [fn () => Responses::text('Data not found', 404)],
]);

it('forgets the attempt when nothing was sent because login failed', function () use ($consumption) {
    [$client, $http, $clock] = guarded();
    $http->queue(Responses::text('bad credentials', 401), login($clock), Responses::datadis('{"timeCurve":[]}'));

    expect(fn () => $consumption($client))->toThrow(DatadisException::class);

    $consumption($client);

    expect($http->requests())->toHaveCount(3)
        ->and($http->requests()[2]->getUri()->getPath())->toBe('/api-private/api/get-consumption-data-v2');
});

it('does not guard the endpoints the rule does not cover, which Datadis answers every time', function (Closure $call, string $answer) {
    [$client, $http, $clock] = guarded();
    $http->queue(login($clock), Responses::datadis($answer), Responses::datadis($answer));

    $call($client);
    $call($client);

    expect($http->requests())->toHaveCount(3);
})->with([
    'supplies' => [fn (DatadisClient $c) => $c->getSupplies(), '{"supplies":[],"distributorError":[]}'],
    'contract detail' => [fn (DatadisClient $c) => $c->getContractDetail(Cups::fromString('ES0000000000000000AA0A'), '2'), '{"contract":[],"distributorError":[]}'],
    'distributors' => [fn (DatadisClient $c) => $c->getDistributorsWithSupplies(), '{"distExistenceUser":{"distributorCodes":["2"]},"distributorError":[]}'],
]);

it('does not record queries refused before sending', function () use ($maxPower) {
    [$client, $http, $clock] = guarded();
    $http->queue(login($clock), Responses::datadis('{"maxPower":[]}'));

    expect(fn () => $client->getMaxPower(Cups::fromString('ES0000000000000000AA0A'), '', Month::of(2026, 1), Month::of(2026, 1)))
        ->toThrow(DatadisException::class);

    $maxPower($client);

    expect($http->requests())->toHaveCount(2);
});

it('works without a ledger, sending whatever it is asked', function () use ($consumption) {
    $s = Scenario::make();
    $s->http->queue(Responses::datadis('{"timeCurve":[]}'), Responses::datadis('{"timeCurve":[]}'));

    $consumption($s->client);
    $consumption($s->client);

    expect($s->http->requests())->toHaveCount(3);
});

it('sends nothing when the ledger store cannot be read or cannot record the attempt', function (QuirkyCache $store) use ($consumption) {
    $http = new FakeHttpClient;
    $clock = new FrozenClock;
    $ledger = new RequestLedger($store, new RequestFingerprinter('a-secret-key-of-at-least-32-bytes!!'), $clock);
    $client = new DatadisClient(new DatadisConfig('A00000000', 'secret', baseUrl: 'https://datadis.test'), http: $http, clock: $clock, ledger: $ledger);

    try {
        $consumption($client);
    } catch (LedgerUnavailableException $e) {
        expect($e->endpoint)->toBe('get-consumption-data-v2')
            ->and($e->requestSent)->toBeFalse()
            ->and($http->requests())->toBe([]);

        return;
    }

    throw new LogicException('Expected a LedgerUnavailableException.');
})->with([
    'unreadable' => [fn () => new QuirkyCache(throwOnGet: true)],
    'refusing to record' => [fn () => new QuirkyCache(failSet: true)],
]);

it('does not keep a query blocked when the token store fails before sending', function () use ($consumption) {
    $http = new FakeHttpClient;
    $clock = new FrozenClock(new DateTimeImmutable('2026-09-15 10:00:00', new DateTimeZone('Europe/Madrid')));
    $ledger = new RequestLedger(new InMemoryCache($clock), new RequestFingerprinter('a-secret-key-of-at-least-32-bytes!!'), $clock);
    $client = new DatadisClient(
        new DatadisConfig('A00000000', 'secret', baseUrl: 'https://datadis.test'),
        http: $http,
        tokenCache: new QuirkyCache(throwOnGet: true, throwOnSet: true),
        clock: $clock,
        ledger: $ledger,
    );
    $http->queue(login($clock), Responses::datadis('{"timeCurve":[]}'));

    $consumption($client);

    expect($http->requests())->toHaveCount(2);
});

it('keeps the original failure when the ledger cannot forget an unsent query', function () use ($consumption) {
    $http = new FakeHttpClient;
    $clock = new FrozenClock;
    $ledger = new RequestLedger(new QuirkyCache(throwOnDelete: true), new RequestFingerprinter('a-secret-key-of-at-least-32-bytes!!'), $clock);
    $client = new DatadisClient(new DatadisConfig('A00000000', 'secret', baseUrl: 'https://datadis.test'), http: $http, clock: $clock, ledger: $ledger);
    $http->queue(Responses::text('bad credentials', 401));

    expect(fn () => $consumption($client))->toThrow(AuthenticationException::class);
});

it('treats max power queries with and without authorizedNif as the same query, as the manual keys them', function () {
    [$client, $http, $clock] = guarded();
    $http->queue(login($clock), Responses::datadis('{"maxPower":[]}'));

    $client->getMaxPower(Cups::fromString('ES0000000000000000AA0A'), '2', Month::of(2026, 1), Month::of(2026, 1), Nif::fromString('00000000T'));

    expect(fn () => $client->getMaxPower(Cups::fromString('ES0000000000000000AA0A'), '2', Month::of(2026, 1), Month::of(2026, 1)))
        ->toThrow(RepetitionWindowException::class);
});

it('keeps consumption queries with and without authorizedNif apart, as the manual keys them', function () {
    [$client, $http, $clock] = guarded();
    $http->queue(login($clock), Responses::datadis('{"timeCurve":[]}'), Responses::datadis('{"timeCurve":[]}'));

    $client->getConsumptionData(Cups::fromString('ES0000000000000000AA0A'), '2', 5, Month::of(2026, 1), Month::of(2026, 1), authorizedNif: Nif::fromString('00000000T'));
    $client->getConsumptionData(Cups::fromString('ES0000000000000000AA0A'), '2', 5, Month::of(2026, 1), Month::of(2026, 1));

    expect($http->requests())->toHaveCount(3);
});

it('does not keep a query blocked when its request could not even be built', function () use ($consumption) {
    $http = new FakeHttpClient;
    $clock = new FrozenClock(new DateTimeImmutable('2026-09-15 10:00:00', new DateTimeZone('Europe/Madrid')));
    $ledger = new RequestLedger(new InMemoryCache($clock), new RequestFingerprinter('a-secret-key-of-at-least-32-bytes!!'), $clock);
    $failing = new class implements RequestFactoryInterface
    {
        public bool $fail = true;

        public function createRequest(string $method, $uri): RequestInterface
        {
            if ($this->fail && str_contains((string) $uri, 'get-consumption-data')) {
                throw new InvalidArgumentException('Unusable URI.');
            }

            return (new HttpFactory)->createRequest($method, $uri);
        }
    };
    $client = new DatadisClient(new DatadisConfig('A00000000', 'secret', baseUrl: 'https://datadis.test'), http: $http, clock: $clock, requestFactory: $failing, ledger: $ledger);
    $http->queue(login($clock), Responses::datadis('{"timeCurve":[]}'));

    expect(fn () => $consumption($client))->toThrow(ConfigurationException::class);

    $failing->fail = false;
    $consumption($client);

    expect($http->requests())->toHaveCount(2);
});
