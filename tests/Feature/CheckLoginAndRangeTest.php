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

it('leaves the cached token in place when a fresh check fails, so the next call uses it', function () {
    $s = Scenario::make();
    $s->client->checkLogin();
    $s->http->queue(Responses::datadisError('bad credentials', 401), Responses::datadis('{"supplies":[],"distributorError":[]}'));

    expect(fn () => $s->client->checkLogin(fresh: true))->toThrow(AuthenticationException::class);

    $s->client->getSupplies();

    expect(array_map(fn ($r) => $r->getUri()->getPath(), array_slice($s->http->requests(), 2)))
        ->toBe(['/api-private/api/get-supplies-v2']);
});

it('never uses again a token it dropped, even when the token cache cannot delete it', function (Closure $call, int $logins) {
    $clock = new FrozenClock(new DateTimeImmutable('2026-09-15 10:00:00', new DateTimeZone('Europe/Madrid')));
    $http = new FakeHttpClient;
    $client = new DatadisClient(
        new DatadisConfig('A00000000', 'secret', baseUrl: 'https://datadis.test'),
        http: $http,
        tokenCache: new QuirkyCache(failDelete: true),
        clock: $clock,
    );
    $first = Tokens::datadis($clock->now()->getTimestamp());
    $http->queue(Responses::text($first));
    $client->checkLogin();
    $clock->advance(60);
    $second = Tokens::datadis($clock->now()->getTimestamp());

    $call($client, $http, $second);

    $requests = $http->requests();
    $data = array_values(array_filter($requests, fn ($r) => $r->getMethod() === 'GET'));

    expect(array_filter($requests, fn ($r) => $r->getMethod() === 'POST'))->toHaveCount($logins)
        ->and(array_map(fn ($r) => $r->getHeaderLine('Authorization'), array_slice($data, -1)))->toBe($data === [] ? [] : ['Bearer '.$second]);
})->with([
    'a fresh check logs in' => [function ($client, $http, $second) {
        $http->queue(Responses::text($second));
        $client->checkLogin(fresh: true);
    }, 2],
    'a rejected token is not sent again' => [function ($client, $http, $second) {
        $http->queue(Responses::datadisError('{"status":401}', 401), Responses::text($second), Responses::datadis('{"supplies":[],"distributorError":[]}'), Responses::datadis('{"supplies":[],"distributorError":[]}'));
        $client->getSupplies();
        $client->getSupplies();
    }, 2],
]);

it('takes the same token back from a new login without logging in on every call', function (bool $deleteFails, Closure $renew) {
    $clock = new FrozenClock(new DateTimeImmutable('2026-09-15 10:00:00', new DateTimeZone('Europe/Madrid')));
    $http = new FakeHttpClient;
    $client = new DatadisClient(
        new DatadisConfig('A00000000', 'secret', baseUrl: 'https://datadis.test'),
        http: $http,
        tokenCache: new QuirkyCache(failDelete: $deleteFails),
        clock: $clock,
    );
    // Datadis hands back the token it was asked to replace, still valid.
    $same = Tokens::datadis($clock->now()->getTimestamp());
    $http->queue(Responses::text($same));
    $client->checkLogin();

    $renew($client, $http, $same);
    $http->queue(Responses::datadis('{"supplies":[],"distributorError":[]}'), Responses::datadis('{"supplies":[],"distributorError":[]}'));
    $client->getSupplies();
    $client->getSupplies();

    $logins = array_filter($http->requests(), fn ($r) => $r->getMethod() === 'POST');
    $last = $http->requests()[count($http->requests()) - 1];

    expect($logins)->toHaveCount(2)->and($last->getHeaderLine('Authorization'))->toBe('Bearer '.$same);
})->with([
    'a plain cache' => [false],
    'a cache whose delete() fails' => [true],
])->with([
    'after a 401' => [function ($client, $http, $same) {
        $http->queue(Responses::datadisError('{"status":401}', 401), Responses::text($same), Responses::datadis('{"supplies":[],"distributorError":[]}'));
        $client->getSupplies();
    }],
    'after a fresh check' => [function ($client, $http, $same) {
        $http->queue(Responses::text($same));
        $client->checkLogin(fresh: true);
    }],
]);
