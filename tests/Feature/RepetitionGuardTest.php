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
use Lenorix\DatadisClient\Tests\Support\AtomicCache;
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

it('remembers its own queries without a ledger, so one client never repeats one', function () use ($consumption) {
    $s = Scenario::make();
    $s->http->queue(Responses::datadis('{"timeCurve":[]}'), Responses::datadis('{"timeCurve":[]}'));

    $consumption($s->client);

    expect(fn () => $consumption($s->client))->toThrow(RepetitionWindowException::class)
        ->and(fn () => $consumption($s->client->forHolder(Nif::fromString('00000000T'))))->not->toThrow(RepetitionWindowException::class)
        ->and($s->http->requests())->toHaveCount(3);
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

/** @return array{DatadisClient, FakeHttpClient} a worker of an application, on a store several workers share */
function worker(AtomicCache $store, FrozenClock $clock, bool $atomic): array
{
    $http = new FakeHttpClient;
    $ledger = new RequestLedger($atomic ? $store : $store->withoutAdd(), new RequestFingerprinter('a-secret-key-of-at-least-32-bytes!!'), $clock);

    return [new DatadisClient(new DatadisConfig('A00000000', 'secret', baseUrl: 'https://datadis.test'), http: $http, clock: $clock, ledger: $ledger), $http];
}

it('lets only one of two workers send the same query at once when the store adds atomically', function () use ($consumption) {
    $clock = new FrozenClock(new DateTimeImmutable('2026-09-15 10:00:00', new DateTimeZone('Europe/Madrid')));
    // Each worker checks before the other has written: only an atomic add can tell them apart.
    $store = new AtomicCache(staleReads: true);
    [$first, $firstHttp] = worker($store, $clock, atomic: true);
    [$second, $secondHttp] = worker($store, $clock, atomic: true);
    $firstHttp->queue(login($clock), Responses::datadis('{"timeCurve":[]}'));

    $consumption($first);

    expect(fn () => $consumption($second))->toThrow(RepetitionWindowException::class)
        ->and($secondHttp->requests())->toBe([]);
});

it('sends the query from both workers in that race with a plain PSR-16 store, as documented', function () use ($consumption) {
    $clock = new FrozenClock(new DateTimeImmutable('2026-09-15 10:00:00', new DateTimeZone('Europe/Madrid')));
    $store = new AtomicCache(staleReads: true);
    [$first, $firstHttp] = worker($store, $clock, atomic: false);
    [$second, $secondHttp] = worker($store, $clock, atomic: false);
    $firstHttp->queue(login($clock), Responses::datadis('{"timeCurve":[]}'));
    $secondHttp->queue(login($clock), Responses::datadis('{"timeCurve":[]}'));

    $consumption($first);
    $consumption($second);

    expect($secondHttp->requests())->toHaveCount(2);
});

it('frees an unsent query again with an atomic store, and sends nothing when the store fails', function () use ($consumption) {
    $clock = new FrozenClock(new DateTimeImmutable('2026-09-15 10:00:00', new DateTimeZone('Europe/Madrid')));
    $store = new AtomicCache;
    [$client, $http] = worker($store, $clock, atomic: true);
    $http->queue(Responses::text('bad credentials', 401), login($clock), Responses::datadis('{"timeCurve":[]}'));

    expect(fn () => $consumption($client))->toThrow(AuthenticationException::class);

    $consumption($client);
    $store->failAdd = true;
    $store->items = [];

    expect($http->requests())->toHaveCount(3)
        ->and(fn () => $consumption($client))->toThrow(LedgerUnavailableException::class)
        ->and($http->requests())->toHaveCount(3);
});

it('says when a query refused locally was last attempted and from when it is allowed again', function (?int $window) use ($consumption) {
    $http = new FakeHttpClient;
    $clock = new FrozenClock(new DateTimeImmutable('2026-09-15 10:00:00', new DateTimeZone('Europe/Madrid')));
    $ledger = new RequestLedger(new InMemoryCache($clock), new RequestFingerprinter('a-secret-key-of-at-least-32-bytes!!'), $clock, windowSeconds: $window);
    $client = new DatadisClient(new DatadisConfig('A00000000', 'secret', baseUrl: 'https://datadis.test'), http: $http, clock: $clock, ledger: $ledger);
    $http->queue(login($clock), Responses::datadis('{"timeCurve":[],"distributorError":[]}'));
    $sentAt = $clock->now()->getTimestamp();

    $consumption($client);
    $clock->advance(3600);

    try {
        $consumption($client);
    } catch (RepetitionWindowException $e) {
        expect($e->lastAttemptAt?->getTimestamp())->toBe($sentAt)
            ->and($e->availableAt?->getTimestamp())->toBe($sentAt + ($window ?? RequestLedger::WINDOW_SECONDS))
            ->and($e->getMessage())->toContain('allowed again from')
            ->and($http->requests())->toHaveCount(2);

        // Once that moment comes, the guard lets it through.
        $clock->advance($e->availableAt->getTimestamp() - $clock->now()->getTimestamp());
        $http->queue(Responses::datadis('{"timeCurve":[],"distributorError":[]}'));
        $consumption($client);

        expect($http->requests())->toHaveCount(3);

        return;
    }

    throw new LogicException('Expected a RepetitionWindowException.');
})->with(['the default window' => [null], 'a window of two days' => [2 * 86400]]);

it('cannot say either for the 429 of Datadis itself', function () use ($consumption) {
    $s = Scenario::make();
    $s->http->queue(Responses::datadisError('Consulta ya realizada en las últimas 24 horas. ', 429));

    try {
        $consumption($s->client);
    } catch (RepetitionWindowException $e) {
        expect($e->httpStatus)->toBe(429)->and($e->lastAttemptAt)->toBeNull()->and($e->availableAt)->toBeNull();

        return;
    }

    throw new LogicException('Expected a RepetitionWindowException.');
});

it('says which months a refused query asked for, and keeps Datadis\'s own 429 as the cause', function () use ($consumption) {
    [$client, $http] = guarded();
    $http->queue(login(new FrozenClock), Responses::datadis('{"timeCurve":[],"distributorError":[]}'));
    $consumption($client);

    try {
        $consumption($client);
        throw new LogicException('Expected a RepetitionWindowException.');
    } catch (RepetitionWindowException $local) {
        expect($local->startDate?->format())->toBe('2026/01')->and($local->endDate?->format())->toBe('2026/01');
    }

    $s = Scenario::make();
    $s->http->queue(Responses::datadisError('Consulta ya realizada en las últimas 24 horas. ', 429));

    try {
        $s->client->getMaxPower(Cups::fromString('ES0000000000000000AA0A'), '2', Month::of(2026, 5), Month::of(2026, 7));
    } catch (RepetitionWindowException $e) {
        expect($e->httpStatus)->toBe(429)
            ->and($e->requestSent)->toBeTrue()
            ->and($e->startDate?->format())->toBe('2026/05')->and($e->endDate?->format())->toBe('2026/07')
            ->and($e->lastAttemptAt)->toBeNull()
            ->and($e->detail)->toBe($e->getPrevious()?->detail)
            ->and($e->getMessage())->toBe($e->getPrevious()?->getMessage());

        return;
    }

    throw new LogicException('Expected a RepetitionWindowException.');
});
