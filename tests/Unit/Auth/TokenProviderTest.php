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
use Lenorix\DatadisClient\Tests\Support\Scenario;
use Lenorix\DatadisClient\Tests\Support\Stack;
use Lenorix\DatadisClient\Tests\Support\Tokens;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;

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

it('logs in again when a store that ignores the TTL hands back an expired token', function () {
    $clock = new FrozenClock;
    $cache = new QuirkyCache;
    $stack = new Stack(clock: $clock, cache: $cache);
    $stack->http->queue($stack->loginOk(3600));

    $first = $stack->tokens->token();
    $clock->advance(3600);
    $stack->http->queue($stack->loginOk(3600));
    $second = $stack->tokens->token();

    expect($second)->not->toBe($first)
        ->and($stack->http->requests())->toHaveCount(2)
        ->and(array_values($cache->items))->toBe([$second]);
});

it('takes a token from a store that ignores the TTL while it is still valid', function () {
    $clock = new FrozenClock;
    $stack = new Stack(clock: $clock, cache: new QuirkyCache);
    $stack->http->queue($stack->loginOk(3600));

    $first = $stack->tokens->token();
    $clock->advance(3600 - TokenProvider::SKEW_SECONDS - 1);

    expect($stack->tokens->token())->toBe($first)->and($stack->http->requests())->toHaveCount(1);
});

it('falls back to a conservative lifetime when the token has no exp', function () {
    $clock = new FrozenClock;
    $stack = new Stack(clock: $clock, cache: new InMemoryCache($clock));
    $stack->http->queue(Responses::text('opaque.without.claims'), Responses::text('another.opaque.token'));

    expect($stack->tokens->token())->toBe('opaque.without.claims');

    $clock->advance(TokenProvider::FALLBACK_TTL_SECONDS - TokenProvider::SKEW_SECONDS - 1);
    expect($stack->tokens->token())->toBe('opaque.without.claims');

    $clock->advance(2);
    expect($stack->tokens->token())->toBe('another.opaque.token');
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

    $other = new Stack(cache: $cache, config: new DatadisConfig('00000000T', 'pw', baseUrl: 'https://datadis.test'));
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

it('keeps the password out of a failed login, whatever the HTTP client says about the request', function (string $said) {
    $stack = new Stack;
    $said = str_replace('secret', Stack::PASSWORD, $said);
    $stack->http->queue(new ConnectException($said, new Request('POST', 'https://datadis.test')));

    try {
        $stack->tokens->token();
    } catch (TransportException $e) {
        expect($e->detail)->toBeNull()
            ->and($e->getMessage())->not->toContain(Stack::PASSWORD)
            ->and((string) $e)->not->toContain(Stack::PASSWORD);

        return;
    }

    throw new LogicException('Expected a TransportException.');
})->with([
    'as sent' => ['cURL error 28 while sending username=A00000000&password=secret'],
    'url encoded' => ['failed: password%3Dsecret'],
    'in a dump of the body' => ['body: {"password":"secret"}'],
]);

it('still says what the HTTP client reported about a data request, without identifiers', function () {
    $s = Scenario::make();
    $s->http->queue(new ConnectException('cURL error 28 for ES0000000000000000AA0A', new Request('GET', 'https://datadis.test')));

    try {
        $s->client->getMaxPower(Cups::fromString('ES0000000000000000AA0A'), '2', Month::of(2026, 8));
    } catch (TransportException $e) {
        expect($e->detail)->toContain('cURL error 28')->not->toContain('ES0000000000000000AA0A');

        return;
    }

    throw new LogicException('Expected a TransportException.');
});

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
            ->and((string) $e->detail)->not->toContain('A00000000');

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

it('ignores a token store that fails to forget, and does not use the token again', function () {
    $stack = new Stack(cache: new QuirkyCache(throwOnDelete: true));
    $stack->http->queue($stack->loginOk(), $stack->loginOk(7200));
    $first = $stack->tokens->token();

    $stack->tokens->invalidate();

    expect($stack->tokens->token())->not->toBe($first)->and($stack->http->requests())->toHaveCount(2);
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

    $other = new Stack(cache: $cache, config: new DatadisConfig('A00000000', 'pw', baseUrl: 'https://other.test'));
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

it('removes the password a login error echoes in any of the forms a server writes a form field in', function (Closure $echo) {
    $password = 'review only-secret/ñ*~"\'&<1>';
    $stack = new Stack(config: new DatadisConfig('A00000000', $password, baseUrl: 'https://datadis.test'));
    $stack->http->queue(Responses::text('bad login: '.$echo($password), 401));

    try {
        $stack->tokens->token();
    } catch (AuthenticationException $e) {
        expect($e->detail)->toContain('[redacted]')->not->toContain('only')
            ->and($e->getMessage())->not->toContain('only');

        return;
    }

    throw new LogicException('Expected an AuthenticationException.');
})->with([
    'as it is' => [fn (string $p) => "password={$p}"],
    'form encoded' => [fn (string $p) => 'password='.urlencode($p)],
    'percent encoded' => [fn (string $p) => 'password='.rawurlencode($p)],
    'percent encoded in lower case' => [fn (string $p) => 'password='.preg_replace_callback('/%[0-9A-F]{2}/', fn ($m) => strtolower($m[0]), rawurlencode($p))],
    'in JSON' => [fn (string $p) => json_encode(['password' => $p])],
    'in JSON, unicode escaped' => [fn (string $p) => json_encode(['password' => $p], JSON_UNESCAPED_SLASHES)],
    'in HTML' => [fn (string $p) => '<td>'.htmlspecialchars($p).'</td>'],
    'in HTML with &#39;' => [fn (string $p) => '<td>'.str_replace('&#039;', '&#39;', htmlspecialchars($p, ENT_QUOTES)).'</td>'],
    'in HTML with &apos;' => [fn (string $p) => '<td>'.htmlspecialchars($p, ENT_QUOTES | ENT_HTML5).'</td>'],
    'as Java\'s URLEncoder writes it' => [fn (string $p) => 'password='.str_replace('%2A', '*', urlencode($p))],
    'in HTML with named entities' => [fn (string $p) => '<td>'.htmlentities($p, ENT_QUOTES | ENT_HTML401).'</td>'],
    'in HTML with decimal entities' => [fn (string $p) => '<td>'.mb_encode_numericentity($p, [0, 0x10FFFF, 0, 0x10FFFF], 'UTF-8').'</td>'],
    'in HTML with hexadecimal entities' => [fn (string $p) => '<td>'.mb_encode_numericentity($p, [0, 0x10FFFF, 0, 0x10FFFF], 'UTF-8', true).'</td>'],
    'as Latin-1 bytes' => [fn (string $p) => 'password='.mb_convert_encoding($p, 'ISO-8859-1', 'UTF-8')],
    'as the message of a JSON error' => [fn (string $p) => json_encode(['message' => 'wrong password '.$p])],
]);

it('quotes no field of a JSON login error but its message', function () {
    $stack = new Stack;
    $stack->http->queue(Responses::text('{"error":"Unauthorized","ownerName":"JUAN PEREZ"}', 401));

    try {
        $stack->tokens->token();
    } catch (AuthenticationException $e) {
        expect($e->detail)->toBe('Unauthorized')->and($e->getMessage())->not->toContain('PEREZ');

        return;
    }

    throw new LogicException('Expected an AuthenticationException.');
});

it('scrubs an echoed username, also one whose control character was not checked', function () {
    $stack = new Stack(config: new DatadisConfig('00000000A', Stack::PASSWORD, baseUrl: 'https://datadis.test', checkUsernameControl: false));
    $stack->http->queue(Responses::text('unknown user 00000000A', 401));

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

it('drops the token without failing when the token store cannot be read', function () {
    $stack = new Stack(cache: new QuirkyCache(throwOnGet: true));
    $stack->http->queue($stack->loginOk(), $stack->loginOk());
    $stack->tokens->token();

    $stack->tokens->invalidate();
    $stack->tokens->token();

    expect($stack->http->requests())->toHaveCount(2);
});

it('takes only a JWT from a login, never a word a 200 may carry', function (string $body) {
    $stack = new Stack;
    $stack->http->queue(Responses::text($body));

    expect(fn () => $stack->tokens->token())->toThrow(UninterpretableResponseException::class, 'not a token');
})->with(['null', 'OK', 'false', 'Unauthorized', 'two.parts', 'a.b.c.d', 'a.b/c.d']);
