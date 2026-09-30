<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Lenorix\DatadisClient\Auth\TokenProvider;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Exceptions\AuthenticationException;
use Lenorix\DatadisClient\Exceptions\RequestRejectedException;
use Lenorix\DatadisClient\Exceptions\ServiceUnavailableException;
use Lenorix\DatadisClient\Exceptions\TransportException;
use Lenorix\DatadisClient\Exceptions\UninterpretableResponseException;
use Lenorix\DatadisClient\Support\InMemoryCache;
use Lenorix\DatadisClient\Tests\Support\FrozenClock;
use Lenorix\DatadisClient\Tests\Support\QuirkyCache;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Lenorix\DatadisClient\Tests\Support\Stack;
use Lenorix\DatadisClient\Tests\Support\Tokens;

it('logs in once and reuses the cached token', function () {
    $stack = new Stack;
    $stack->http->queue($stack->loginOk());

    $first = $stack->tokens->token();
    $second = $stack->tokens->token();

    expect($first)->toBe($second)->and($stack->http->requests())->toHaveCount(1);
});

it('logs in again once the token is about to expire', function () {
    $clock = new FrozenClock;
    $stack = new Stack(clock: $clock, cache: new InMemoryCache($clock));
    $stack->http->queue($stack->loginOk(3600), $stack->loginOk(3600));

    $stack->tokens->token();
    $clock->advance(3600 - TokenProvider::SKEW_SECONDS - 1);
    $stack->tokens->token();
    expect($stack->http->requests())->toHaveCount(1);

    $clock->advance(2);
    $stack->tokens->token();
    expect($stack->http->requests())->toHaveCount(2);
});

it('falls back to a conservative lifetime when the token has no exp', function () {
    $clock = new FrozenClock;
    $stack = new Stack(clock: $clock, cache: new InMemoryCache($clock));
    $stack->http->queue(Responses::text('opaque-token-without-claims'), Responses::text('another-opaque-token'));

    expect($stack->tokens->token())->toBe('opaque-token-without-claims');

    $clock->advance(TokenProvider::FALLBACK_TTL_SECONDS - TokenProvider::SKEW_SECONDS - 1);
    expect($stack->tokens->token())->toBe('opaque-token-without-claims');

    $clock->advance(2);
    expect($stack->tokens->token())->toBe('another-opaque-token');
});

it('uses a token that is already expired once without caching it', function () {
    $stack = new Stack;
    $stack->http->queue(
        Responses::text(Tokens::jwt(['exp' => $stack->clock->now()->getTimestamp() - 10])),
        $stack->loginOk(),
    );

    $stack->tokens->token();
    $stack->tokens->token();

    expect($stack->http->requests())->toHaveCount(2);
});

it('forgets the token on invalidate', function () {
    $stack = new Stack;
    $stack->http->queue($stack->loginOk(), $stack->loginOk());

    $stack->tokens->token();
    $stack->tokens->invalidate();
    $stack->tokens->token();

    expect($stack->http->requests())->toHaveCount(2);
});

it('shares the cached token with another provider using the same cache and account', function () {
    $cache = new InMemoryCache(new FrozenClock);
    $one = new Stack(cache: $cache);
    $one->http->queue($one->loginOk());
    $one->tokens->token();

    $two = new Stack(cache: $cache);

    expect($two->tokens->token())->toBe($one->tokens->token())->and($two->http->requests())->toHaveCount(0);
});

it('does not share tokens between accounts', function () {
    $cache = new InMemoryCache(new FrozenClock);
    $one = new Stack(cache: $cache);
    $one->http->queue($one->loginOk(subject: 'one'));
    $one->tokens->token();

    $other = new Stack(cache: $cache, config: new DatadisConfig('87654321X', 'pw', baseUrl: 'https://datadis.test'));
    $other->http->queue($other->loginOk(subject: 'other'));
    $other->tokens->token();

    expect($other->http->requests())->toHaveCount(1);
});

it('cleans quotes and a Bearer prefix from the token', function (string $body) {
    $stack = new Stack;
    $stack->http->queue(Responses::text($body));

    expect($stack->tokens->token())->toBe('abc.def.ghi');
})->with(['"abc.def.ghi"', "  abc.def.ghi\n", 'Bearer abc.def.ghi', "\xEF\xBB\xBFabc.def.ghi"]);

it('fails as a rejected login without sending a data request', function (int $status, string $class, bool $preflight) {
    $stack = new Stack;
    $stack->http->queue(Responses::text('nope', $status));

    try {
        $stack->tokens->token();
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf($class)
            ->and($e->requestSent)->toBe(! $preflight)
            ->and($e->endpoint)->toBe('login')
            ->and($e->httpStatus)->toBe($status);

        return;
    }

    throw new LogicException('Expected an exception.');
})->with([
    'unauthorized' => [401, AuthenticationException::class, true],
    'forbidden' => [403, AuthenticationException::class, true],
    'server error' => [500, ServiceUnavailableException::class, true],
    'bad gateway' => [502, ServiceUnavailableException::class, true],
]);

it('refuses login bodies that are not a token', function (string $body) {
    $stack = new Stack;
    $stack->http->queue(Responses::text($body));

    $stack->tokens->token();
})->with([
    'empty' => '',
    'html page' => '<!DOCTYPE html><html>WAF</html>',
    'json error' => '{"error":"x"}',
    'token with inner whitespace' => 'abc def',
    'token with a newline inside' => "abc\ndef",
])->throws(UninterpretableResponseException::class);

it('reports a network failure during login as unsent', function () {
    $stack = new Stack;
    $stack->http->queue(new ConnectException('cURL error 28', new Request('POST', 'https://datadis.test')));

    try {
        $stack->tokens->token();
    } catch (TransportException $e) {
        expect($e->requestSent)->toBeFalse()->and($e->endpoint)->toBe('login');

        return;
    }

    throw new LogicException('Expected a TransportException.');
});

it('never puts the password or the token in an exception message', function () {
    $stack = new Stack;
    $stack->http->queue(Responses::text('bad '.Stack::PASSWORD, 401));

    try {
        $stack->tokens->token();
    } catch (Throwable $e) {
        expect($e->getMessage())->not->toContain(Stack::PASSWORD)
            ->and((string) $e->detail)->not->toContain('12345678Z');

        return;
    }

    throw new LogicException('Expected an exception.');
});

it('treats an unreadable token store as empty and still logs in', function () {
    $stack = new Stack(cache: new QuirkyCache(throwOnGet: true, throwOnSet: true));
    $stack->http->queue($stack->loginOk(subject: 'fresh'));

    expect($stack->tokens->token())->toBeString()->and($stack->http->requests())->toHaveCount(1);
});

it('ignores a token store that refuses to save', function () {
    $stack = new Stack(cache: new QuirkyCache(failSet: true));
    $stack->http->queue($stack->loginOk(), $stack->loginOk());

    $stack->tokens->token();
    $stack->tokens->token();

    expect($stack->http->requests())->toHaveCount(2);
});

it('drops a cached value that is not a usable token and logs in again', function (mixed $poison) {
    $cache = new QuirkyCache;
    $stack = new Stack(cache: $cache);
    $stack->http->queue($stack->loginOk());
    $stack->tokens->token();
    foreach (array_keys($cache->items) as $key) {
        $cache->items[$key] = $poison;
    }
    $stack->http->queue($stack->loginOk(subject: 'again'));

    $token = $stack->tokens->token();

    expect($token)->toMatch('/^[A-Za-z0-9._~+\/=-]+$/')->and($stack->http->requests())->toHaveCount(2);
})->with([['Bearer abc'], ["abc\r\nX: y"], [''], [123], [['x']]]);

it('ignores a token store that fails to forget', function () {
    $stack = new Stack(cache: new QuirkyCache(throwOnDelete: true));

    $stack->tokens->invalidate();

    expect($stack->http->requests())->toBe([]);
});

it('reports any other refused login as a rejected request that was not sent', function () {
    $stack = new Stack;
    $stack->http->queue(Responses::text('bad request', 400));

    try {
        $stack->tokens->token();
    } catch (RequestRejectedException $e) {
        expect($e->requestSent)->toBeFalse()->and($e->httpStatus)->toBe(400);

        return;
    }

    throw new LogicException('Expected a RequestRejectedException.');
});

it('does not share tokens between base URLs of the same account', function () {
    $cache = new InMemoryCache(new FrozenClock);
    $one = new Stack(cache: $cache);
    $one->http->queue($one->loginOk());
    $one->tokens->token();

    $other = new Stack(cache: $cache, config: new DatadisConfig('12345678Z', 'pw', baseUrl: 'https://other.test'));
    $other->http->queue($other->loginOk());
    $other->tokens->token();

    expect($other->http->requests())->toHaveCount(1);
});

it('stores the token under a valid PSR-16 key', function () {
    $cache = new QuirkyCache;
    $stack = new Stack(cache: $cache);
    $stack->http->queue($stack->loginOk());
    $stack->tokens->token();

    expect(array_keys($cache->items))->toHaveCount(1)
        ->and(array_keys($cache->items)[0])->toMatch('/^[A-Za-z0-9_.]{1,64}$/');
});

it('does not store a token that would expire within the safety margin', function () {
    $cache = new QuirkyCache;
    $stack = new Stack(cache: $cache);
    $stack->http->queue($stack->loginOk(TokenProvider::SKEW_SECONDS));
    $stack->tokens->token();

    expect($cache->items)->toBe([]);
});

it('removes a poisoned token from the store even if saving the new one fails', function () {
    $cache = new QuirkyCache;
    $stack = new Stack(cache: $cache);
    $stack->http->queue($stack->loginOk());
    $stack->tokens->token();
    $key = array_key_first($cache->items);
    $cache->items[$key] = 'Bearer poisoned';
    $cache->failSet = true;
    $stack->http->queue($stack->loginOk());

    $stack->tokens->token();

    expect($cache->items)->not->toHaveKey($key);
});

it('scrubs an echoed username that is not shaped like a NIF', function () {
    $stack = new Stack(config: new DatadisConfig('partner-account', Stack::PASSWORD, baseUrl: 'https://datadis.test'));
    $stack->http->queue(Responses::text('unknown user PARTNER-ACCOUNT', 401));

    try {
        $stack->tokens->token();
    } catch (AuthenticationException $e) {
        expect($e->detail)->toBe('unknown user [redacted]')
            ->and($e->getMessage())->toBe('login: Datadis answered HTTP 401 · unknown user [redacted]');

        return;
    }

    throw new LogicException('Expected an AuthenticationException.');
});

it('says a non-token login answer was not sent and carries no detail', function () {
    $stack = new Stack;
    $stack->http->queue(Responses::text('<html>'));

    try {
        $stack->tokens->token();
    } catch (UninterpretableResponseException $e) {
        expect($e->requestSent)->toBeFalse()->and($e->detail)->toBe('')->and($e->getMessage())->toBe('login: the login answer is not a token.');

        return;
    }

    throw new LogicException('Expected an UninterpretableResponseException.');
});

it('cleans spaces inside the quotes of a token', function () {
    $stack = new Stack;
    $stack->http->queue(Responses::text('" abc.def.ghi "'));

    expect($stack->tokens->token())->toBe('abc.def.ghi');
});

it('stores a token that stays valid one second beyond the safety margin', function () {
    $cache = new QuirkyCache;
    $stack = new Stack(cache: $cache);
    $stack->http->queue($stack->loginOk(TokenProvider::SKEW_SECONDS + 1));
    $stack->tokens->token();

    expect($cache->items)->toHaveCount(1);
});

it('cleans a quoted token followed by a newline', function () {
    $stack = new Stack;
    $stack->http->queue(Responses::text("\"abc.def.ghi\"\n"));

    expect($stack->tokens->token())->toBe('abc.def.ghi');
});
