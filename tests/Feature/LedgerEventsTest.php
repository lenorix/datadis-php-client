<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\Exceptions\AuthenticationException;
use Lenorix\DatadisClient\Exceptions\DatadisException;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\DatadisClient\Guard\LedgerEvent;
use Lenorix\DatadisClient\Guard\LedgerEventKind;
use Lenorix\DatadisClient\Guard\RequestFingerprinter;
use Lenorix\DatadisClient\Guard\RequestLedger;
use Lenorix\DatadisClient\Tests\Support\AtomicCache;
use Lenorix\DatadisClient\Tests\Support\FakeHttpClient;
use Lenorix\DatadisClient\Tests\Support\FrozenClock;
use Lenorix\DatadisClient\Tests\Support\QuirkyCache;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Lenorix\DatadisClient\Tests\Support\Scenario;
use Lenorix\DatadisClient\Tests\Support\Tokens;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;

/** @return array{DatadisClient, FakeHttpClient, FrozenClock, ArrayObject<int, LedgerEvent>} */
function historyClient(bool $atomic, ?Closure $listener = null, bool $login = true): array
{
    $events = new ArrayObject;
    $s = Scenario::make(
        ledger: fn (FrozenClock $clock) => Scenario::ledger($clock, $atomic ? new AtomicCache : null, $listener ?? fn (LedgerEvent $e) => $events->append($e)),
        login: $login,
    );

    return [$s->client, $s->http, $s->clock, $events];
}

it('tells of each query claimed and remembered, without its CUPS', function (bool $atomic) {
    [$client, $http, $clock, $events] = historyClient($atomic);
    $cups = Cups::fromString(Scenario::CUPS);
    $http->queue(Responses::datadis('{"maxPower":[],"distributorError":[]}'));

    $client->getMaxPower($cups, '2', Month::of(2026, 8));
    $client->rememberConsumptionData($clock->now()->modify('-3 hours'), $cups, '2', 5, Month::of(2026, 8));

    // A network failure may have reached Datadis: the claim stays, and nothing is released.
    $http->queue(new ConnectException('down', new Request('GET', 'https://datadis.test')));
    try {
        $client->getMaxPower($cups, '2', Month::of(2026, 7));
    } catch (DatadisException) {
    }

    $kinds = array_map(fn (LedgerEvent $e) => [$e->kind, $e->endpoint, $e->at->getTimestamp()], $events->getArrayCopy());

    expect($kinds[0])->toBe([LedgerEventKind::Claimed, 'get-max-power-v2', $clock->now()->getTimestamp()])
        ->and($kinds[1])->toBe([LedgerEventKind::Remembered, 'get-consumption-data-v2', $clock->now()->getTimestamp() - 3 * 3600])
        ->and($kinds[2])->toBe([LedgerEventKind::Claimed, 'get-max-power-v2', $clock->now()->getTimestamp()])
        ->and($kinds)->toHaveCount(3)
        ->and($events[0]->key)->toStartWith('datadis_query_')->not->toContain(Scenario::CUPS)
        ->and($events[0]->key)->not->toBe($events[1]->key);
})->with(['a plain store' => [false], 'an atomic store' => [true]]);

it('tells of a claim released when the login failed before the request left', function (bool $atomic) {
    [$client, $http, $clock, $events] = historyClient($atomic, login: false);
    $http->queue(Responses::datadisError('bad credentials', 401));

    try {
        $client->getMaxPower(Cups::fromString(Scenario::CUPS), '2', Month::of(2026, 8));
    } catch (DatadisException $e) {
        expect($e->requestSent)->toBeFalse();
    }

    expect(array_map(fn (LedgerEvent $e) => $e->kind, $events->getArrayCopy()))->toBe([LedgerEventKind::Claimed, LedgerEventKind::Released])
        ->and($events[1]->key)->toBe($events[0]->key)
        ->and($events[1]->endpoint)->toBe('get-max-power-v2');
})->with(['a plain store' => [false], 'an atomic store' => [true]]);

it('never lets a failing history decide whether a query goes', function () {
    [$client, $http] = historyClient(false, fn () => throw new RuntimeException('history down'));
    $http->queue(Responses::datadis('{"maxPower":[],"distributorError":[]}'));

    $client->getMaxPower(Cups::fromString(Scenario::CUPS), '2', Month::of(2026, 8));

    expect($http->requests())->toHaveCount(2);
});

it('frees, and tells it freed, a query whose key the store could not delete', function () {
    $http = new FakeHttpClient;
    $clock = Scenario::clock();
    $events = new ArrayObject;
    $cache = new QuirkyCache(failDelete: true);
    $ledger = new RequestLedger($cache, new RequestFingerprinter(Scenario::SECRET), $clock, onChange: fn (LedgerEvent $e) => $events->append($e));
    $client = new DatadisClient(Scenario::config(), http: $http, clock: $clock, ledger: $ledger);
    $http->queue(Responses::datadisError('bad credentials', 401), Responses::text(Tokens::datadis($clock->now()->getTimestamp())), Responses::datadis('{"maxPower":[],"distributorError":[]}'));
    $query = fn () => $client->getMaxPower(Cups::fromString(Scenario::CUPS), '2', Month::of(2026, 8));

    // The login fails before the request: the query never left, so it is free again.
    expect($query)->toThrow(AuthenticationException::class)
        ->and(array_map(fn (LedgerEvent $e) => $e->kind, $events->getArrayCopy()))->toBe([LedgerEventKind::Claimed, LedgerEventKind::Released]);

    $query();

    expect($http->requests())->toHaveCount(3);
});

it('does not tell it freed a query the store could not free, and keeps the original failure', function () {
    $http = new FakeHttpClient;
    $clock = Scenario::clock();
    $events = new ArrayObject;
    $cache = new QuirkyCache(failDelete: true);
    $ledger = new RequestLedger($cache, new RequestFingerprinter(Scenario::SECRET), $clock, onChange: function (LedgerEvent $e) use ($events, $cache): void {
        $events->append($e);
        $cache->failSet = true;   // the store fails from now on
    });
    $client = new DatadisClient(Scenario::config(), http: $http, clock: $clock, ledger: $ledger);
    $http->queue(Responses::datadisError('bad credentials', 401));

    expect(fn () => $client->getMaxPower(Cups::fromString(Scenario::CUPS), '2', Month::of(2026, 8)))->toThrow(AuthenticationException::class)
        ->and(array_map(fn (LedgerEvent $e) => $e->kind, $events->getArrayCopy()))->toBe([LedgerEventKind::Claimed]);
});

it('tells of a query the guard refused, with the attempt that holds it and when it may go', function (bool $atomic) {
    [$client, $http, $clock, $events] = historyClient($atomic);
    $cups = Cups::fromString(Scenario::CUPS);
    $http->queue(Responses::datadis('{"timeCurve":[],"distributorError":[]}'));
    $client->getMaxPower($cups, '2', Month::of(2026, 8));
    $sentAt = $clock->now()->getTimestamp();
    $clock->advance(3600);

    try {
        $client->getMaxPower($cups, '2', Month::of(2026, 8));
    } catch (RepetitionWindowException $refusal) {
    }

    $refused = $events[1];

    expect(array_map(fn (LedgerEvent $e) => $e->kind, $events->getArrayCopy()))->toBe([LedgerEventKind::Claimed, LedgerEventKind::Refused])
        ->and($refused->key)->toBe($events[0]->key)
        ->and($refused->endpoint)->toBe('get-max-power-v2')
        ->and($refused->at->getTimestamp())->toBe($sentAt + 3600)
        ->and($refused->lastAttemptAt?->getTimestamp())->toBe($sentAt)
        ->and($refused->availableAt?->getTimestamp())->toBe($refusal->availableAt->getTimestamp())
        ->and($events[0]->lastAttemptAt)->toBeNull()
        ->and($events[0]->availableAt)->toBeNull()
        ->and($http->requests())->toHaveCount(2);
})->with(['a plain store' => [false], 'an atomic store' => [true]]);

it('does not tell of a refusal when a lookup only reads the ledger', function () {
    [$client, $http, $clock, $events] = historyClient(false);
    $cups = Cups::fromString(Scenario::CUPS);
    $http->queue(Responses::datadis('{"timeCurve":[],"distributorError":[]}'));
    $client->getMaxPower($cups, '2', Month::of(2026, 8));

    expect($client->maxPowerBlockedUntil($cups, '2', Month::of(2026, 8)))->not->toBeNull()
        ->and(array_map(fn (LedgerEvent $e) => $e->kind, $events->getArrayCopy()))->toBe([LedgerEventKind::Claimed]);
});
