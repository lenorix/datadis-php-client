<?php

declare(strict_types=1);

use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Http\RequestFactory;
use Lenorix\DatadisClient\PublicApi\Community;
use Lenorix\DatadisClient\PublicApi\PublicSearchQuery;
use Lenorix\DatadisClient\PublicApiClient;
use Lenorix\DatadisClient\Tests\Support\FakeHttpClient;
use Lenorix\DatadisClient\Tests\Support\FrozenClock;
use Lenorix\DatadisClient\Tests\Support\QuirkyCache;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Lenorix\DatadisClient\Tests\Support\Tokens;

it('judges the expiry of the cached token by the clock it is given, not the system one', function () {
    $clock = new FrozenClock(new DateTimeImmutable('2026-09-15 10:00:00', new DateTimeZone('Europe/Madrid')));
    $issuedAt = static fn () => Tokens::datadis($clock->now()->getTimestamp());
    $http = (new FakeHttpClient)->queue(
        Responses::text($issuedAt()), Responses::json('[]'),   // the first call: a login, then the search
        Responses::json('[]'),                                // 12 hours later: the token is still good
    );
    $client = new PublicApiClient(
        new DatadisConfig('A00000000', 'secret', baseUrl: 'https://datadis.test'),
        $http,
        tokenCache: new QuirkyCache,   // ignores the lifetime: only the `exp` of the token decides
        clock: $clock,
    );
    $query = new PublicSearchQuery(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'), [Community::Madrid]);
    $logins = fn () => count(array_filter($http->requests(), fn ($r) => str_ends_with($r->getUri()->getPath(), RequestFactory::LOGIN_PATH)));

    $client->apiSearch($query);
    $clock->advance(12 * 3600);
    $client->apiSearch($query);
    expect($logins())->toBe(1);

    // 25 hours after the login the token has expired: a new login (and, since the cache is read again, a new token).
    $http->queue(Responses::text($issuedAt()), Responses::json('[]'));
    $clock->advance(13 * 3600);
    $client->apiSearch($query);
    expect($logins())->toBe(2);
});
