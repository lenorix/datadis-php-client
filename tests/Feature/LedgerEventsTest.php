<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Exceptions\DatadisException;
use Lenorix\DatadisClient\Guard\LedgerEvent;
use Lenorix\DatadisClient\Guard\LedgerEventKind;
use Lenorix\DatadisClient\Guard\RequestFingerprinter;
use Lenorix\DatadisClient\Guard\RequestLedger;
use Lenorix\DatadisClient\Support\InMemoryCache;
use Lenorix\DatadisClient\Tests\Support\AtomicCache;
use Lenorix\DatadisClient\Tests\Support\FakeHttpClient;
use Lenorix\DatadisClient\Tests\Support\FrozenClock;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Lenorix\DatadisClient\Tests\Support\Scenario;
use Lenorix\DatadisClient\Tests\Support\Tokens;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;

/** @return array{DatadisClient, FakeHttpClient, FrozenClock, ArrayObject<int, LedgerEvent>} */
function historyClient(bool $atomic, ?Closure $listener = null, bool $login = true): array
{
    $http = new FakeHttpClient;
    $clock = new FrozenClock(new DateTimeImmutable('2026-09-15 10:00:00', new DateTimeZone('Europe/Madrid')));
    $events = new ArrayObject;
    $ledger = new RequestLedger(
        $atomic ? new AtomicCache : new InMemoryCache($clock),
        new RequestFingerprinter('a-secret-key-of-at-least-32-bytes!!'),
        $clock,
        onChange: $listener ?? fn (LedgerEvent $e) => $events->append($e),
    );
    if ($login) {
        $http->queue(Responses::text(Tokens::datadis($clock->now()->getTimestamp())));
    }

    return [new DatadisClient(new DatadisConfig('A00000000', 'secret', baseUrl: 'https://datadis.test'), http: $http, clock: $clock, ledger: $ledger), $http, $clock, $events];
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
