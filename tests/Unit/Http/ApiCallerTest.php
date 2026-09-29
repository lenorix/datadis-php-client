<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Lenorix\DatadisClient\Exceptions\AuthenticationException;
use Lenorix\DatadisClient\Exceptions\NoDataException;
use Lenorix\DatadisClient\Exceptions\TransportException;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Lenorix\DatadisClient\Tests\Support\Stack;
use Lenorix\DatadisClient\Tests\Support\Tokens;

const SUPPLIES = '/api-private/api/get-supplies-v2';

it('logs in, calls the endpoint with the bearer token and returns decoded JSON', function () {
    $stack = new Stack;
    $stack->http->queue($stack->loginOk(), Responses::json('{"supplies":[],"distributorError":[]}'));

    $decoded = $stack->caller->get(SUPPLIES, ['authorizedNif' => null], 'get-supplies-v2');

    expect($decoded)->toBe(['supplies' => [], 'distributorError' => []])
        ->and($stack->http->requests())->toHaveCount(2)
        ->and($stack->http->lastRequest()->getHeaderLine('Authorization'))->toStartWith('Bearer ');
});

it('re-logs in exactly once when the token is rejected and retries the call', function () {
    $stack = new Stack;
    $stack->http->queue(
        $stack->loginOk(subject: 'first'),
        Responses::text('expired', 401),
        $stack->loginOk(subject: 'second'),
        Responses::json('[{"cups":"x"}]'),
    );

    $decoded = $stack->caller->get(SUPPLIES, [], 'get-supplies-v2');

    $requests = $stack->http->requests();
    $tokenOf = fn (int $i) => $requests[$i]->getHeaderLine('Authorization');

    expect($decoded)->toBe([['cups' => 'x']])
        ->and($requests)->toHaveCount(4)
        ->and($tokenOf(1))->not->toBe($tokenOf(3));
});

it('gives up after a second 401 and does not loop', function () {
    $stack = new Stack;
    $stack->http->queue($stack->loginOk(), Responses::text('no', 401), $stack->loginOk(), Responses::text('no', 401));

    try {
        $stack->caller->get(SUPPLIES, [], 'get-supplies-v2');
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
        $stack->caller->get(SUPPLIES, [], 'get-supplies-v2');
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
        $stack->caller->get(SUPPLIES, [], 'get-consumption-data-v2');
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
    $uri = 'https://datadis.test/x?cups=ES0031300000000001JN0F&authorizedNif=12345678Z';
    $stack->http->queue($stack->loginOk(), new ConnectException("cURL error 28 for {$uri}", new Request('GET', $uri)));

    try {
        $stack->caller->get(SUPPLIES, [], 'get-consumption-data-v2');
    } catch (TransportException $e) {
        expect($e->getMessage())->not->toContain('ES0031300000000001JN0F')->not->toContain('12345678Z')
            ->and((string) $e->detail)->not->toContain('ES0031300000000001JN0F')->not->toContain('12345678Z')
            ->and($e->getPrevious())->toBeNull();

        return;
    }

    throw new LogicException('Expected a TransportException.');
});

it('passes classification failures through untouched', function () {
    $stack = new Stack;
    $stack->http->queue($stack->loginOk(), Responses::text('Data not found', 404));

    $stack->caller->get(SUPPLIES, [], 'get-supplies-v2');
})->throws(NoDataException::class);

it('never lets the password or the token reach an exception message', function () {
    $stack = new Stack;
    $token = Tokens::jwt(['exp' => $stack->clock->now()->getTimestamp() + 3600]);
    $stack->http->queue(Responses::text($token), Responses::text("boom {$token}", 500));

    try {
        $stack->caller->get(SUPPLIES, [], 'get-supplies-v2');
    } catch (Throwable $e) {
        expect($e->getMessage())->not->toContain(Stack::PASSWORD)->not->toContain($token)
            ->and((string) $e->detail)->not->toContain($token);

        return;
    }

    throw new LogicException('Expected an exception.');
});
