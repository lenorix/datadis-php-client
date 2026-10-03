<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use Lenorix\DatadisClient\Exceptions\AuthenticationException;
use Lenorix\DatadisClient\Exceptions\DatadisException;
use Lenorix\DatadisClient\Exceptions\NoDataException;
use Lenorix\DatadisClient\Exceptions\TransportException;
use Lenorix\DatadisClient\Http\Transport;
use Lenorix\DatadisClient\Tests\Support\FakeHttpClient;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Lenorix\DatadisClient\Tests\Support\Stack;
use Lenorix\DatadisClient\Tests\Support\Tokens;
use Psr\Http\Message\ResponseInterface;

const SUPPLIES = '/api-private/api/get-supplies-v2';

const CONSUMPTION = '/api-private/api/get-consumption-data-v2';

it('logs in, calls the endpoint with the bearer token and returns decoded JSON', function () {
    $stack = new Stack;
    $stack->http->queue($stack->loginOk(), Responses::datadis('{"supplies":[],"distributorError":[]}'));

    $decoded = $stack->caller->get(SUPPLIES, ['authorizedNif' => null], 'get-supplies-v2', sendAgainAfter401: true);

    expect($decoded)->toBe(['supplies' => [], 'distributorError' => []])
        ->and($stack->http->requests())->toHaveCount(2)
        ->and($stack->http->lastRequest()->getHeaderLine('Authorization'))->toStartWith('Bearer ');
});

it('re-logs in exactly once when the token is rejected and retries the call', function () {
    $stack = new Stack;
    $stack->http->queue(
        $stack->loginOk(subject: 'first'),
        refusedToken(),
        $stack->loginOk(subject: 'second'),
        Responses::datadis('[{"cups":"x"}]'),
    );

    $decoded = $stack->caller->get(SUPPLIES, [], 'get-supplies-v2', sendAgainAfter401: true);

    $requests = $stack->http->requests();
    $tokenOf = fn (int $i) => $requests[$i]->getHeaderLine('Authorization');

    expect($decoded)->toBe([['cups' => 'x']])
        ->and($requests)->toHaveCount(4)
        ->and($tokenOf(1))->not->toBe($tokenOf(3));
});

it('gives up after a second 401 and does not loop', function () {
    $stack = new Stack;
    $stack->http->queue($stack->loginOk(), refusedToken(), $stack->loginOk(), refusedToken());

    try {
        $stack->caller->get(SUPPLIES, [], 'get-supplies-v2', sendAgainAfter401: true);
    } catch (AuthenticationException $e) {
        expect($e->requestSent)->toBeTrue()->and($stack->http->requests())->toHaveCount(4);

        return;
    }

    throw new LogicException('Expected an AuthenticationException.');
});

it('does not send the data request when login fails, and says so', function () {
    $stack = new Stack;
    $stack->http->queue(Responses::text('bad credentials', 401));

    try {
        $stack->caller->get(SUPPLIES, [], 'get-supplies-v2', sendAgainAfter401: true);
    } catch (AuthenticationException $e) {
        expect($e->requestSent)->toBeFalse()->and($stack->http->requests())->toHaveCount(1);

        return;
    }

    throw new LogicException('Expected an AuthenticationException.');
});

it('treats a network failure on a data call as possibly sent and does not retry', function () {
    $stack = new Stack;
    $stack->http->queue($stack->loginOk(), new ConnectException('cURL error 28: Operation timed out', new Request('GET', 'https://datadis.test')));

    try {
        $stack->caller->get(CONSUMPTION, [], 'get-consumption-data-v2', sendAgainAfter401: true);
    } catch (TransportException $e) {
        expect($e->requestSent)->toBeTrue()
            ->and($e->endpoint)->toBe('get-consumption-data-v2')
            ->and($stack->http->requests())->toHaveCount(2);

        return;
    }

    throw new LogicException('Expected a TransportException.');
});

it('does not leak query identifiers through a transport failure', function () {
    $stack = new Stack;
    $uri = 'https://datadis.test/x?cups=ES0000000000000000AA0A&authorizedNif=A00000000';
    $stack->http->queue($stack->loginOk(), new ConnectException("cURL error 28 for {$uri}", new Request('GET', $uri)));

    try {
        $stack->caller->get(CONSUMPTION, [], 'get-consumption-data-v2', sendAgainAfter401: true);
    } catch (TransportException $e) {
        expect($e->getMessage())->not->toContain('ES0000000000000000AA0A')->not->toContain('A00000000')
            ->and((string) $e->detail)->not->toContain('ES0000000000000000AA0A')->not->toContain('A00000000')
            ->and($e->getPrevious())->toBeNull();

        return;
    }

    throw new LogicException('Expected a TransportException.');
});

it('passes classification failures through untouched', function () {
    $stack = new Stack;
    $stack->http->queue($stack->loginOk(), Responses::text('Data not found', 404));

    $stack->caller->get(SUPPLIES, [], 'get-supplies-v2', sendAgainAfter401: true);
})->throws(NoDataException::class);

it('never lets the password or the token reach an exception message', function () {
    $stack = new Stack;
    $token = Tokens::jwt(['exp' => $stack->clock->now()->getTimestamp() + 3600]);
    $stack->http->queue(Responses::text($token), Responses::text("boom {$token}", 500));

    try {
        $stack->caller->get(SUPPLIES, [], 'get-supplies-v2', sendAgainAfter401: true);
    } catch (Throwable $e) {
        expect($e->getMessage())->not->toContain(Stack::PASSWORD)->not->toContain($token)
            ->and((string) $e->detail)->not->toContain($token);

        return;
    }

    throw new LogicException('Expected an exception.');
});

it('returns the raw text of an answer that is allowed to be empty', function () {
    $stack = new Stack;
    $stack->http->queue($stack->loginOk(), refusedToken(), $stack->loginOk(), Responses::empty(200));

    expect($stack->caller->getText('/api-private/api/cancel-authorization', [], 'cancel-authorization', sendAgainAfter401: true))->toBe('')
        ->and($stack->http->requests())->toHaveCount(4);
});

it('reports the call as sent when logging in again after a 401 fails', function () {
    $stack = new Stack;
    $stack->http->queue($stack->loginOk(), refusedToken(), Responses::text('bad credentials', 401));

    try {
        $stack->caller->get(CONSUMPTION, [], 'get-consumption-data-v2', sendAgainAfter401: true);
    } catch (AuthenticationException $e) {
        expect($e->requestSent)->toBeTrue()
            ->and($e->httpStatus)->toBe(401)
            ->and($e->endpoint)->toBe('get-consumption-data-v2')
            ->and($e->getPrevious())->toBeInstanceOf(AuthenticationException::class);

        return;
    }

    throw new LogicException('Expected an AuthenticationException.');
});

it('reports the call as sent when the network fails while logging in again', function () {
    $stack = new Stack;
    $stack->http->queue($stack->loginOk(), refusedToken(), new ConnectException('down', new Request('POST', 'https://datadis.test')));

    try {
        $stack->caller->get(CONSUMPTION, [], 'get-consumption-data-v2', sendAgainAfter401: true);
    } catch (DatadisException $e) {
        expect($e->requestSent)->toBeTrue();

        return;
    }

    throw new LogicException('Expected an exception.');
});

it('wraps whatever a misbehaving HTTP client throws', function (bool $preflight) {
    $transport = new Transport((new FakeHttpClient)->queue(new RuntimeException('boom ES0000000000000000AA0A')), new HttpFactory);

    try {
        $transport->send(new Request('GET', 'https://datadis.test/x'), 'get-supplies-v2', $preflight);
    } catch (TransportException $e) {
        expect($e->requestSent)->toBe(! $preflight)
            ->and($e->getMessage().$e->detail)->not->toContain('ES0000000000000000AA0A');

        return;
    }

    throw new LogicException('Expected a TransportException.');
})->with([true, false]);

/** The answer Datadis gives to a token it does not accept (verified). */
function refusedToken(): ResponseInterface
{
    return Responses::datadisError(datadisFixture('errors/401-spring.json'), 401);
}
