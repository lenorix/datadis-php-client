<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Utils;
use Lenorix\DatadisClient\Http\RetryingClient;
use Lenorix\DatadisClient\Tests\Support\FakeHttpClient;
use Lenorix\DatadisClient\Tests\Support\FrozenClock;
use Lenorix\DatadisClient\Tests\Support\Responses;

/** @return array{RetryingClient, FakeHttpClient, ArrayObject<int, int>} */
function retrying(int $maxRetries = 2, int $baseDelayMs = 1000, int $maxDelayMs = 30000): array
{
    $http = new FakeHttpClient;
    $sleeps = new ArrayObject;
    $client = new RetryingClient($http, $maxRetries, $baseDelayMs, $maxDelayMs, sleep: fn (int $ms) => $sleeps->append($ms), random: fn () => 1.0);

    return [$client, $http, $sleeps];
}

function get(string $path): Request
{
    return new Request('GET', 'https://datadis.test'.$path);
}

function networkFailure(): ConnectException
{
    return new ConnectException('cURL error 7', new Request('GET', 'https://datadis.test'));
}

const SAFE = '/api-private/api/get-supplies-v2';

it('passes a successful answer through without waiting', function () {
    [$client, $http, $sleeps] = retrying();
    $http->queue(Responses::json('[]'));

    expect($client->sendRequest(get(SAFE))->getStatusCode())->toBe(200)
        ->and($http->requests())->toHaveCount(1)
        ->and($sleeps->getArrayCopy())->toBe([]);
});

it('retries network failures and gateway errors on unguarded endpoints with growing waits', function () {
    [$client, $http, $sleeps] = retrying();
    $http->queue(networkFailure(), Responses::empty(503), Responses::json('[]'));

    expect($client->sendRequest(get(SAFE))->getStatusCode())->toBe(200)
        ->and($http->requests())->toHaveCount(3)
        ->and($sleeps->getArrayCopy())->toBe([1000, 2000]);
});

it('gives up after the last retry with the last outcome', function () {
    [$client, $http] = retrying(maxRetries: 2);
    $http->queue(Responses::empty(502), Responses::empty(502), Responses::empty(504));

    expect($client->sendRequest(get(SAFE))->getStatusCode())->toBe(504)->and($http->requests())->toHaveCount(3);

    [$client, $http] = retrying(maxRetries: 1);
    $http->queue(networkFailure(), networkFailure());

    expect(fn () => $client->sendRequest(get(SAFE)))->toThrow(ConnectException::class);
});

it('never retries a call that may count or change data, nor one it does not know', function (string $path, Closure $outcome) {
    [$client, $http, $sleeps] = retrying();
    $http->queue($outcome(), Responses::json('[]'));

    try {
        $client->sendRequest(get($path));
    } catch (ConnectException) {
    }

    expect($http->requests())->toHaveCount(1)->and($sleeps->getArrayCopy())->toBe([]);
})->with([
    '/api-private/api/get-consumption-data-v2',
    '/api-private/api/get-consumption-data',
    '/api-private/api/get-max-power-v2',
    '/api-private/api/get-reactive-data-v2',
    '/api-private/api/new-authorization',
    '/api-private/api/cancel-authorization',
    '/api-private/api/partner-delete-user',
    'a call added to Datadis later' => '/api-private/api/get-something-new-v2',
    'a path outside the API' => '/api-private/other/get-supplies',
    'a guarded call behind a base path that mentions the public API' => '/api-public/gateway/api-private/api/get-consumption-data-v2',
    'an unknown public call' => '/api-public/api-delete-everything',
])->with([
    'network failure' => [fn () => networkFailure()],
    'bad gateway' => [fn () => Responses::empty(502)],
]);

it('retries every call that is safe to repeat, in both versions and behind a base path', function (string $path) {
    [$client, $http] = retrying();
    $http->queue(Responses::empty(503), Responses::json('[]'));

    expect($client->sendRequest(get($path))->getStatusCode())->toBe(200)->and($http->requests())->toHaveCount(2);
})->with([
    '/api-private/api/get-supplies',
    '/api-private/api/get-distributors-with-supplies-v2',
    '/api-private/api/get-contract-detail-v2',
    '/api-private/api/get-groups-v2',
    '/api-private/api/list-authorization',
    '/api-private/api/partner-user-list',
    '/api-private/api/partner-agreement-date',
    '/api-public/api-search',
    '/api-public/api-sum-search-auto',
    '/proxy/datadis/api-public/api-search-auto',
    '/proxy/datadis/api-private/api/get-supplies-v2',
]);

it('never retries client errors, 429 or a plain 500', function (int $status) {
    [$client, $http] = retrying();
    $http->queue(Responses::empty($status), Responses::json('[]'));

    expect($client->sendRequest(get(SAFE))->getStatusCode())->toBe($status)->and($http->requests())->toHaveCount(1);
})->with([400, 401, 403, 404, 429, 500]);

it('does not retry a request the client refused to build', function () {
    [$client, $http] = retrying();
    $http->queue(new RequestException('malformed', get(SAFE)), Responses::json('[]'));

    expect(fn () => $client->sendRequest(get(SAFE)))->toThrow(RequestException::class)
        ->and($http->requests())->toHaveCount(1);
});

it('honours Retry-After in seconds and as a date, and gives up when it is too long', function () {
    [$client, $http, $sleeps] = retrying(maxDelayMs: 10000);
    $http->queue(Responses::text('', 503, ['Retry-After' => '3']), Responses::json('[]'));
    $client->sendRequest(get(SAFE));

    expect($sleeps->getArrayCopy())->toBe([3000]);

    [$client, $http] = retrying(maxDelayMs: 10000);
    $http->queue(Responses::text('', 503, ['Retry-After' => '120']), Responses::json('[]'));

    expect($client->sendRequest(get(SAFE))->getStatusCode())->toBe(503)->and($http->requests())->toHaveCount(1);
});

it('ignores a Retry-After it cannot read', function (string $header) {
    [$client, $http, $sleeps] = retrying();
    $http->queue(Responses::text('', 503, ['Retry-After' => $header]), Responses::json('[]'));
    $client->sendRequest(get(SAFE));

    expect($sleeps->getArrayCopy())->toBe([1000]);
})->with(['soon', '-5', '1e9', '']);

it('retries the login and sends the same body every time', function () {
    [$client, $http] = retrying();
    $request = (new Request('POST', 'https://datadis.test/nikola-auth/tokens/login'))->withBody(Utils::streamFor('username=a&password=b'));
    $bodies = [];
    $capture = function ($r) use (&$bodies) {
        // getContents() reads from the current position, so an unrewound body would come back empty.
        $bodies[] = $r->getBody()->getContents();

        return count($bodies) < 2 ? throw networkFailure() : Responses::text('token');
    };
    $http->queue($capture, $capture);

    $client->sendRequest($request);

    expect($bodies)->toBe(['username=a&password=b', 'username=a&password=b']);
});

it('spreads the waits with jitter and caps them', function () {
    $http = new FakeHttpClient;
    $sleeps = [];
    $client = new RetryingClient($http, 5, 1000, 3000, sleep: function (int $ms) use (&$sleeps) {
        $sleeps[] = $ms;
    }, random: fn () => 0.0);
    $http->queue(...array_fill(0, 6, Responses::empty(503)));

    $client->sendRequest(get(SAFE));

    expect($sleeps)->toBe([500, 1000, 1500, 1500, 1500]);
});

it('refuses nonsensical settings', function (array $arguments) {
    new RetryingClient(new FakeHttpClient, ...$arguments);
})->with([
    [['maxRetries' => -1]],
    [['maxRetries' => 11]],
    [['baseDelayMs' => 0]],
    [['maxDelayMs' => 10, 'baseDelayMs' => 100]],
])->throws(InvalidArgumentException::class);

it('reads Retry-After as an HTTP date', function () {
    $http = new FakeHttpClient;
    $sleeps = [];
    $clock = new FrozenClock(new DateTimeImmutable('2026-09-15 10:00:00 UTC'));
    $client = new RetryingClient($http, 2, 1000, 30000, sleep: function (int $ms) use (&$sleeps) {
        $sleeps[] = $ms;
    }, clock: $clock);
    $http->queue(
        Responses::text('', 503, ['Retry-After' => 'Tue, 15 Sep 2026 10:00:05 GMT']),
        Responses::text('', 503, ['Retry-After' => 'Tue, 15 Sep 2020 10:00:05 GMT']),
        Responses::json('[]'),
    );

    $client->sendRequest(get(SAFE));

    expect($sleeps)->toBe([5000, 0]);
});

it('reads a Retry-After date as GMT whatever the default time zone', function () {
    $previous = date_default_timezone_get();
    date_default_timezone_set('America/New_York');

    try {
        $http = new FakeHttpClient;
        $sleeps = [];
        $clock = new FrozenClock(new DateTimeImmutable('2026-09-15 10:00:00 UTC'));
        $client = new RetryingClient($http, 1, 1000, 30000, sleep: function (int $ms) use (&$sleeps) {
            $sleeps[] = $ms;
        }, clock: $clock);
        $http->queue(Responses::text('', 503, ['Retry-After' => 'Tue, 15 Sep 2026 10:00:05 GMT']), Responses::json('[]'));

        $client->sendRequest(get(SAFE));

        expect($sleeps)->toBe([5000]);
    } finally {
        date_default_timezone_set($previous);
    }
});

it('uses two retries, one second and thirty seconds as defaults', function () {
    $http = (new FakeHttpClient)->queue(Responses::empty(503), Responses::empty(503), Responses::empty(503), Responses::json('[]'));
    $sleeps = [];
    $client = new RetryingClient($http, sleep: function (int $ms) use (&$sleeps) {
        $sleeps[] = $ms;
    }, random: fn () => 1.0);

    expect($client->sendRequest(get(SAFE))->getStatusCode())->toBe(503)
        ->and($sleeps)->toBe([1000, 2000]);

    $http = (new FakeHttpClient)->queue(Responses::text('', 503, ['Retry-After' => '30']), Responses::json('[]'));
    $sleeps = [];
    (new RetryingClient($http, sleep: function (int $ms) use (&$sleeps) {
        $sleeps[] = $ms;
    }))->sendRequest(get(SAFE));

    expect($sleeps)->toBe([30000]);
});

it('retries when Retry-After asks for exactly the maximum wait, not a millisecond more', function () {
    [$client, $http, $sleeps] = retrying(maxDelayMs: 5000);
    $http->queue(Responses::text('', 503, ['Retry-After' => '5']), Responses::json('[]'));

    expect($client->sendRequest(get(SAFE))->getStatusCode())->toBe(200)->and($sleeps->getArrayCopy())->toBe([5000]);

    [$client, $http] = retrying(maxDelayMs: 4999);
    $http->queue(Responses::text('', 503, ['Retry-After' => '5']), Responses::json('[]'));

    expect($client->sendRequest(get(SAFE))->getStatusCode())->toBe(503);
});

it('accepts the extreme settings', function (array $arguments) {
    expect(new RetryingClient(new FakeHttpClient, ...$arguments))->toBeInstanceOf(RetryingClient::class);
})->with([[['maxRetries' => 0]], [['maxRetries' => 10]], [['baseDelayMs' => 1, 'maxDelayMs' => 1]]]);

it('never retries when no retries are allowed', function () {
    [$client, $http] = retrying(maxRetries: 0);
    $http->queue(Responses::empty(503), Responses::json('[]'));

    expect($client->sendRequest(get(SAFE))->getStatusCode())->toBe(503)->and($http->requests())->toHaveCount(1);
});

it('retries a login behind a base path prefix', function () {
    [$client, $http] = retrying();
    $http->queue(networkFailure(), Responses::text('token'));

    $client->sendRequest(new Request('POST', 'https://proxy.test/datadis/nikola-auth/tokens/login'));

    expect($http->requests())->toHaveCount(2);
});

it('does not retry other POST requests', function () {
    [$client, $http] = retrying();
    $http->queue(networkFailure(), Responses::text('x'));

    expect(fn () => $client->sendRequest(new Request('POST', 'https://datadis.test/api-private/api/get-supplies-v2')))->toThrow(ConnectException::class)
        ->and($http->requests())->toHaveCount(1);
});

it('doubles the step on every attempt until the cap', function () {
    $http = (new FakeHttpClient)->queue(...array_fill(0, 5, Responses::empty(503)));
    $sleeps = [];
    (new RetryingClient($http, 4, 100, 100000, sleep: function (int $ms) use (&$sleeps) {
        $sleeps[] = $ms;
    }, random: fn () => 0.0))->sendRequest(get(SAFE));

    expect($sleeps)->toBe([50, 100, 200, 400]);
});

it('keeps the jitter within half and all of the step whatever the random source says', function (int $base, float $random, int $expected) {
    $http = (new FakeHttpClient)->queue(Responses::empty(503), Responses::json('[]'));
    $sleeps = [];
    $sleep = function (int $ms) use (&$sleeps) {
        $sleeps[] = $ms;
    };
    (new RetryingClient($http, 1, $base, 30000, sleep: $sleep, random: fn () => $random))->sendRequest(get(SAFE));

    expect($sleeps)->toBe([$expected]);
})->with([
    'above one' => [1000, 5.0, 1000],
    'below zero' => [1000, -1.0, 500],
    'rounds 1.5 up' => [3, 0.0, 2],
    'rounds 2.1 down' => [3, 0.4, 2],
]);

it('really sleeps with the default sleeper', function () {
    $http = (new FakeHttpClient)->queue(Responses::empty(503), Responses::json('[]'));
    $started = hrtime(true);

    (new RetryingClient($http, 1, baseDelayMs: 20, maxDelayMs: 20))->sendRequest(get(SAFE));

    expect((hrtime(true) - $started) / 1e6)->toBeGreaterThanOrEqual(9.0);
});

it('spreads the waits with the default randomness', function () {
    $http = (new FakeHttpClient)->queue(...array_fill(0, 11, Responses::empty(503)));
    $sleeps = [];
    $sleep = function (int $ms) use (&$sleeps) {
        $sleeps[] = $ms;
    };

    (new RetryingClient($http, 10, 1000, 1_000_000, sleep: $sleep))->sendRequest(get(SAFE));

    $steps = array_map(fn (int $attempt) => 1000 * 2 ** $attempt, array_keys($sleeps));

    expect(array_filter(array_map(fn ($ms, $step) => $ms < $step, $sleeps, $steps)))->not->toBeEmpty();
});
