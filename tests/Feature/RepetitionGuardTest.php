<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Lenorix\DatadisClient\Auth\InMemoryCache;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Exceptions\DatadisException;
use Lenorix\DatadisClient\Exceptions\LedgerUnavailableException;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\DatadisClient\Guard\RequestFingerprinter;
use Lenorix\DatadisClient\Guard\RequestLedger;
use Lenorix\DatadisClient\Tests\Support\FakeHttpClient;
use Lenorix\DatadisClient\Tests\Support\FrozenClock;
use Lenorix\DatadisClient\Tests\Support\QuirkyCache;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Lenorix\DatadisClient\Tests\Support\Scenario;
use Lenorix\DatadisClient\Tests\Support\Tokens;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;
use Psr\Http\Message\ResponseInterface;

/** @return array{DatadisClient, FakeHttpClient, FrozenClock} */
function guarded(): array
{
    $http = new FakeHttpClient;
    $clock = new FrozenClock(new DateTimeImmutable('2026-09-15 10:00:00', new DateTimeZone('Europe/Madrid')));
    $ledger = new RequestLedger(new InMemoryCache($clock), new RequestFingerprinter('a-secret-key-of-at-least-32-bytes!!'), $clock);
    $client = new DatadisClient(new DatadisConfig('12345678Z', 'secret', baseUrl: 'https://datadis.test'), http: $http, clock: $clock, ledger: $ledger);

    return [$client, $http, $clock];
}

function login(FrozenClock $clock): ResponseInterface
{
    return Responses::text(Tokens::jwt(['exp' => $clock->now()->getTimestamp() + 7 * 86400]));
}

$consumption = fn (DatadisClient $c) => $c->consumption(Cups::fromString('ES0031300000000001JN0F'), '2', 5, Month::of(2026, 1), Month::of(2026, 1));
$maxPower = fn (DatadisClient $c) => $c->maxPower(Cups::fromString('ES0031300000000001JN0F'), '2', Month::of(2026, 1), Month::of(2026, 1));
$reactive = fn (DatadisClient $c) => $c->reactive(Cups::fromString('ES0031300000000001JN0F'), '2', Month::of(2026, 1), Month::of(2026, 1));

it('refuses locally to repeat a guarded query within the window', function () use ($consumption) {
    [$client, $http, $clock] = guarded();
    $http->queue(login($clock), Responses::json('{"timeCurve":[],"distributorError":[]}'));

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
    $http->queue(login($clock), Responses::json('{"timeCurve":[]}'), Responses::json('{"timeCurve":[]}'));

    $consumption($client);
    $clock->advance(RequestLedger::WINDOW_SECONDS);
    $consumption($client);

    expect($http->requests())->toHaveCount(3);
});

it('treats max power and reactive with the same window as the same query', function () use ($maxPower, $reactive) {
    [$client, $http, $clock] = guarded();
    $http->queue(login($clock), Responses::json('{"maxPower":[]}'));

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
    'unreadable answer' => [fn () => Responses::json('"maintenance"')],
    'no data' => [fn () => Responses::text('Data not found', 404)],
]);

it('forgets the attempt when nothing was sent because login failed', function () use ($consumption) {
    [$client, $http, $clock] = guarded();
    $http->queue(Responses::text('bad credentials', 401), login($clock), Responses::json('{"timeCurve":[]}'));

    expect(fn () => $consumption($client))->toThrow(DatadisException::class);

    $consumption($client);

    expect($http->requests())->toHaveCount(3)
        ->and($http->requests()[2]->getUri()->getPath())->toBe('/api-private/api/get-consumption-data-v2');
});

it('does not guard the endpoints the rule does not cover', function () {
    [$client, $http, $clock] = guarded();
    $http->queue(login($clock), Responses::json('{"supplies":[]}'), Responses::json('{"supplies":[]}'));

    $client->supplies();
    $client->supplies();

    expect($http->requests())->toHaveCount(3);
});

it('does not record queries refused before sending', function () use ($maxPower) {
    [$client, $http, $clock] = guarded();
    $http->queue(login($clock), Responses::json('{"maxPower":[]}'));

    expect(fn () => $client->maxPower(Cups::fromString('ES0031300000000001JN0F'), '', Month::of(2026, 1), Month::of(2026, 1)))
        ->toThrow(DatadisException::class);

    $maxPower($client);

    expect($http->requests())->toHaveCount(2);
});

it('works without a ledger, sending whatever it is asked', function () use ($consumption) {
    $s = Scenario::make();
    $s->http->queue(Responses::json('{"timeCurve":[]}'), Responses::json('{"timeCurve":[]}'));

    $consumption($s->client);
    $consumption($s->client);

    expect($s->http->requests())->toHaveCount(3);
});

it('sends nothing when the ledger cannot record the attempt', function () use ($consumption) {
    $http = new FakeHttpClient;
    $clock = new FrozenClock;
    $ledger = new RequestLedger(new QuirkyCache(failSet: true), new RequestFingerprinter('a-secret-key-of-at-least-32-bytes!!'), $clock);
    $client = new DatadisClient(new DatadisConfig('12345678Z', 'secret', baseUrl: 'https://datadis.test'), http: $http, clock: $clock, ledger: $ledger);

    expect(fn () => $consumption($client))->toThrow(LedgerUnavailableException::class)
        ->and($http->requests())->toBe([]);
});

it('does not keep a query blocked when the token store fails before sending', function () use ($consumption) {
    $http = new FakeHttpClient;
    $clock = new FrozenClock(new DateTimeImmutable('2026-09-15 10:00:00', new DateTimeZone('Europe/Madrid')));
    $ledger = new RequestLedger(new InMemoryCache($clock), new RequestFingerprinter('a-secret-key-of-at-least-32-bytes!!'), $clock);
    $client = new DatadisClient(
        new DatadisConfig('12345678Z', 'secret', baseUrl: 'https://datadis.test'),
        http: $http,
        tokenCache: new QuirkyCache(throwOnGet: true, throwOnSet: true),
        clock: $clock,
        ledger: $ledger,
    );
    $http->queue(login($clock), Responses::json('{"timeCurve":[]}'));

    $consumption($client);

    expect($http->requests())->toHaveCount(2);
});
