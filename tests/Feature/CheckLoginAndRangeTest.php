<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Exceptions\AuthenticationException;
use Lenorix\DatadisClient\Exceptions\InvalidRequestException;
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
