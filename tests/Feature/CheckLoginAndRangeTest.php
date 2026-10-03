<?php

declare(strict_types=1);

use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Exceptions\AuthenticationException;
use Lenorix\DatadisClient\Exceptions\InvalidRequestException;
use Lenorix\DatadisClient\Tests\Support\FakeHttpClient;
use Lenorix\DatadisClient\Tests\Support\FrozenClock;
use Lenorix\DatadisClient\Tests\Support\QuirkyCache;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Lenorix\DatadisClient\Tests\Support\Scenario;
use Lenorix\DatadisClient\Tests\Support\Tokens;
use Lenorix\DatadisClient\Time\Month;

it('checks the login without reading any data, and tells until when the token lasts', function () {
    $s = Scenario::make();

    $expiry = $s->client->checkLogin();

    expect($expiry?->getTimestamp())->toBe($s->clock->now()->getTimestamp() + 86400)
        ->and(array_map(fn ($r) => $r->getUri()->getPath(), $s->http->requests()))->toBe(['/nikola-auth/tokens/login']);

    // A cached token answers; fresh tries the credentials again.
    $s->client->checkLogin();
    $s->http->queue(Responses::text(Tokens::datadis($s->clock->now()->getTimestamp())));
    $s->client->checkLogin(fresh: true);

    expect($s->http->requests())->toHaveCount(2);
});

it('says nothing about a token without an expiry', function () {
    $s = Scenario::make();
    $s->client->checkLogin();
    $s->http->queue(Responses::text(Tokens::jwt(['sub' => 'account'])));

    expect($s->client->checkLogin(fresh: true))->toBeNull();
});

it('reports refused credentials', function () {
    $s = Scenario::make();
    $s->client->checkLogin();
    $s->http->queue(Responses::datadisError('bad credentials', 401));

    $s->client->checkLogin(fresh: true);
})->throws(AuthenticationException::class);

it('refuses before logging in a range Datadis would refuse', function (Month $from, ?Month $to) {
    $s = Scenario::make();

    expect(fn () => $s->client->assertServedRange($from, $to))->toThrow(InvalidRequestException::class)
        ->and($s->http->requests())->toBe([]);
})->with([
    'reversed' => [Month::of(2026, 8), Month::of(2026, 7)],
    'in the future' => [Month::of(2026, 10), null],
    'the boundary month, two years back' => [Month::of(2024, 9), Month::of(2024, 10)],
]);

it('takes a range Datadis serves', function () {
    $s = Scenario::make();

    $s->client->assertServedRange(Month::of(2024, 10), Month::of(2026, 9));

    expect($s->http->requests())->toBe([]);
});

it('hands the token of a fresh check to every client sharing the token cache, without more logins', function () {
    $clock = new FrozenClock(new DateTimeImmutable('2026-09-15 10:00:00', new DateTimeZone('Europe/Madrid')));
    $cache = new QuirkyCache;
    $http = new FakeHttpClient;
    $config = new DatadisConfig('A00000000', 'secret', baseUrl: 'https://datadis.test');
    $checker = new DatadisClient($config, http: $http, tokenCache: $cache, clock: $clock);
    $worker = new DatadisClient($config, http: $http, tokenCache: $cache, clock: $clock);
    $http->queue(Responses::text(Tokens::datadis($clock->now()->getTimestamp())));
    $checker->checkLogin();
    $clock->advance(60);
    $fresh = Tokens::datadis($clock->now()->getTimestamp());
    $http->queue(Responses::text($fresh), Responses::datadis('{"supplies":[],"distributorError":[]}'));

    $checker->checkLogin(fresh: true);
    $worker->getSupplies();

    expect(array_map(fn ($r) => $r->getUri()->getPath(), $http->requests()))->toBe(['/nikola-auth/tokens/login', '/nikola-auth/tokens/login', '/api-private/api/get-supplies-v2'])
        ->and($http->requests()[2]->getHeaderLine('Authorization'))->toBe('Bearer '.$fresh);
});

it('leaves the shared token cache empty when a fresh check fails, so the next call logs in', function () {
    $s = Scenario::make();
    $s->client->checkLogin();
    $s->http->queue(Responses::datadisError('bad credentials', 401), Responses::text(Tokens::datadis($s->clock->now()->getTimestamp())), Responses::datadis('{"supplies":[],"distributorError":[]}'));

    expect(fn () => $s->client->checkLogin(fresh: true))->toThrow(AuthenticationException::class);

    $s->client->getSupplies();

    expect(array_map(fn ($r) => $r->getUri()->getPath(), array_slice($s->http->requests(), 2)))
        ->toBe(['/nikola-auth/tokens/login', '/api-private/api/get-supplies-v2']);
});
