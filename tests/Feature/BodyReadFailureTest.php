<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Lenorix\DatadisClient\ConnectionSettings;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Exceptions\TransportException;
use Lenorix\DatadisClient\Guard\RequestFingerprinter;
use Lenorix\DatadisClient\Guard\RequestLedger;
use Lenorix\DatadisClient\PublicApi\Community;
use Lenorix\DatadisClient\PublicApi\PublicApi;
use Lenorix\DatadisClient\PublicApi\PublicSearchQuery;
use Lenorix\DatadisClient\Support\InMemoryCache;
use Lenorix\DatadisClient\Tests\Support\FakeHttpClient;
use Lenorix\DatadisClient\Tests\Support\FrozenClock;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Lenorix\DatadisClient\Tests\Support\Scenario;
use Lenorix\DatadisClient\Tests\Support\ThrowingStream;
use Lenorix\DatadisClient\Tests\Support\Tokens;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;

it('reports a body that fails while being read as a transport failure of a sent request', function () {
    $s = Scenario::make();
    $s->http->queue((new Response(200))->withBody(new ThrowingStream));

    try {
        $s->client->consumption(Cups::fromString(Scenario::CUPS), '2', 5, Month::of(2026, 1), Month::of(2026, 1));
    } catch (TransportException $e) {
        expect($e->requestSent)->toBeTrue()
            ->and($e->getPrevious())->toBeNull()
            ->and($e->getMessage().$e->detail)->not->toContain('ES0031300000000001JN0F')->not->toContain('87654321X');

        return;
    }

    throw new LogicException('Expected a TransportException.');
});

it('does not block a query for 24 hours when only the login answer failed to be read', function () {
    $http = new FakeHttpClient;
    $clock = new FrozenClock(new DateTimeImmutable('2026-09-15 10:00:00', new DateTimeZone('Europe/Madrid')));
    $ledger = new RequestLedger(new InMemoryCache($clock), new RequestFingerprinter('a-secret-key-of-at-least-32-bytes!!'), $clock);
    $client = new DatadisClient(new DatadisConfig('12345678Z', 'secret', baseUrl: 'https://datadis.test'), http: $http, clock: $clock, ledger: $ledger);
    $call = fn () => $client->consumption(Cups::fromString(Scenario::CUPS), '2', 5, Month::of(2026, 1), Month::of(2026, 1));
    $http->queue((new Response(200))->withBody(new ThrowingStream));

    try {
        $call();
    } catch (TransportException $e) {
        expect($e->requestSent)->toBeFalse();
    }

    $http->queue(Responses::text(Tokens::jwt(['exp' => $clock->now()->getTimestamp() + 3600])), Responses::datadis('{"timeCurve":[]}'));
    $call();

    expect($http->requests())->toHaveCount(3);
});

it('reports a public API body that fails while being read as a transport failure', function () {
    $http = (new FakeHttpClient)->queue((new Response(200))->withBody(new ThrowingStream));
    $api = new PublicApi(new ConnectionSettings(baseUrl: 'https://datadis.test'), $http);

    $api->search(new PublicSearchQuery(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-02'), [Community::Madrid], ['05']));
})->throws(TransportException::class);
