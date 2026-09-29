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

it('never retries a guarded endpoint', function (string $path, Closure $outcome) {
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
])->with([
    'network failure' => [fn () => networkFailure()],
    'bad gateway' => [fn () => Responses::empty(502)],
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
        $bodies[] = (string) $r->getBody();

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
