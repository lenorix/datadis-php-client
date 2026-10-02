<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Lenorix\DatadisClient\Exceptions\LedgerUnavailableException;
use Lenorix\DatadisClient\Guard\RequestFingerprinter;
use Lenorix\DatadisClient\Guard\RequestLedger;
use Lenorix\DatadisClient\Support\InMemoryCache;
use Lenorix\DatadisClient\Tests\Support\AtomicCache;
use Lenorix\DatadisClient\Tests\Support\FrozenClock;
use Lenorix\DatadisClient\Tests\Support\QuirkyCache;

$query = ['cups' => 'ES0000000000000000AA0A', 'distributorCode' => '2', 'startDate' => '2026/01', 'endDate' => '2026/01'];

function ledger(FrozenClock $clock): RequestLedger
{
    return new RequestLedger(new InMemoryCache($clock), new RequestFingerprinter('a-secret-key-of-at-least-32-bytes!!'), $clock);
}

it('remembers an attempt for 24 hours and a margin for clock differences with Datadis', function () use ($query) {
    $clock = new FrozenClock;
    $ledger = ledger($clock);

    expect($ledger->lastAttempt('A00000000', $query))->toBeNull();

    $ledger->record('A00000000', $query);
    expect($ledger->lastAttempt('A00000000', $query)?->getTimestamp())->toBe($clock->now()->getTimestamp());

    $clock->advance(86400 + 60);
    expect($ledger->lastAttempt('A00000000', $query))->not->toBeNull();

    $clock->advance(RequestLedger::WINDOW_SECONDS - 86400 - 60 - 1);
    expect($ledger->lastAttempt('A00000000', $query))->not->toBeNull();

    $clock->advance(1);
    expect($ledger->lastAttempt('A00000000', $query))->toBeNull();
});

it('forgets an attempt on demand', function () use ($query) {
    $ledger = ledger(new FrozenClock);
    $ledger->record('A00000000', $query);
    $ledger->forget('A00000000', $query);

    expect($ledger->lastAttempt('A00000000', $query))->toBeNull();
});

it('keeps accounts apart', function () use ($query) {
    $ledger = ledger(new FrozenClock);
    $ledger->record('A00000000', $query);

    expect($ledger->lastAttempt('00000000T', $query))->toBeNull();
});

it('stores only a hash, never the CUPS', function () use ($query) {
    $clock = new FrozenClock;
    $cache = new InMemoryCache($clock);
    (new RequestLedger($cache, new RequestFingerprinter('a-secret-key-of-at-least-32-bytes!!'), $clock))->record('A00000000', $query);

    expect(print_r($cache, true))->not->toContain('ES0000000000000000AA0A')->not->toContain('A00000000');
});

it('reads a timestamp that the store gives back as a string', function () use ($query) {
    $clock = new FrozenClock;
    $ledger = new RequestLedger(new QuirkyCache(stringify: true), new RequestFingerprinter('a-secret-key-of-at-least-32-bytes!!'), $clock);
    $ledger->record('A00000000', $query);

    expect($ledger->lastAttempt('A00000000', $query)?->getTimestamp())->toBe($clock->now()->getTimestamp());
});

it('does not block forever when the store ignores the TTL', function () use ($query) {
    $clock = new FrozenClock;
    $ledger = new RequestLedger(new QuirkyCache, new RequestFingerprinter('a-secret-key-of-at-least-32-bytes!!'), $clock);
    $ledger->record('A00000000', $query);

    $clock->advance(RequestLedger::WINDOW_SECONDS);

    expect($ledger->lastAttempt('A00000000', $query))->toBeNull();
});

it('ignores values it did not write', function (mixed $value) use ($query) {
    $cache = new QuirkyCache;
    $ledger = new RequestLedger($cache, new RequestFingerprinter('a-secret-key-of-at-least-32-bytes!!'), new FrozenClock);
    $ledger->record('A00000000', $query);
    foreach (array_keys($cache->items) as $key) {
        $cache->items[$key] = $value;
    }

    expect($ledger->lastAttempt('A00000000', $query))->toBeNull();
})->with([['yesterday'], [null], [['x']], ['-5'], [1.5]]);

it('fails loudly when the store cannot be read or written', function (QuirkyCache $cache, Closure $use) use ($query) {
    $ledger = new RequestLedger($cache, new RequestFingerprinter('a-secret-key-of-at-least-32-bytes!!'), new FrozenClock);

    try {
        $use($ledger, $query);
    } catch (LedgerUnavailableException $e) {
        expect($e->requestSent)->toBeFalse();

        return;
    }

    throw new LogicException('Expected a LedgerUnavailableException.');
})->with([
    'get throws' => [fn () => new QuirkyCache(throwOnGet: true), fn ($l, $q) => $l->lastAttempt('A00000000', $q)],
    'set throws' => [fn () => new QuirkyCache(throwOnSet: true), fn ($l, $q) => $l->record('A00000000', $q)],
    'set refuses' => [fn () => new QuirkyCache(failSet: true), fn ($l, $q) => $l->record('A00000000', $q)],
    'delete throws' => [fn () => new QuirkyCache(throwOnDelete: true), fn ($l, $q) => $l->forget('A00000000', $q)],
]);

it('stores attempts under valid PSR-16 keys', function () use ($query) {
    $cache = new QuirkyCache;
    (new RequestLedger($cache, new RequestFingerprinter('a-secret-key-of-at-least-32-bytes!!'), new FrozenClock))->record('A00000000', $query);

    expect(array_keys($cache->items)[0])->toMatch('/^[A-Za-z0-9_.]{1,64}$/');
});

it('takes a time a little ahead as a recent attempt, but one far in the future as a value it did not write', function (int $ahead, bool $blocks) use ($query) {
    $clock = new FrozenClock;
    $cache = new QuirkyCache;
    $ledger = new RequestLedger($cache, new RequestFingerprinter('a-secret-key-of-at-least-32-bytes!!'), $clock);
    $ledger->record('A00000000', $query);
    foreach (array_keys($cache->items) as $key) {
        $cache->items[$key] = $clock->now()->getTimestamp() + $ahead;
    }

    expect($ledger->lastAttempt('A00000000', $query) !== null)->toBe($blocks);
})->with([
    'another worker one minute ahead' => [60, true],
    'at the tolerance' => [RequestLedger::CLOCK_TOLERANCE_SECONDS, true],
    'just past it' => [RequestLedger::CLOCK_TOLERANCE_SECONDS + 1, false],
    'ten days ahead' => [864000, false],
]);

it('counts a key an atomic store holds even when its value does not, so no two workers can both take it', function (Closure $stored) use ($query) {
    $clock = new FrozenClock;
    $store = new AtomicCache;
    $ledger = new RequestLedger($store, new RequestFingerprinter('a-secret-key-of-at-least-32-bytes!!'), $clock, $store);
    $ledger->record('A00000000', $query);
    foreach (array_keys($store->items) as $key) {
        $store->items[$key] = $stored($clock->now()->getTimestamp());
    }

    expect($ledger->claim('A00000000', $query)?->getTimestamp())->toBe($clock->now()->getTimestamp());
})->with([
    'older than the window' => [fn (int $now) => $now - RequestLedger::WINDOW_SECONDS],
    'far in the future, as from a clock far behind' => [fn (int $now) => $now + 864000],
    'not a time' => [fn () => 'yesterday'],
]);

it('answers a lost atomic claim with the time the other worker sent the query', function () use ($query) {
    $clock = new FrozenClock;
    $store = new AtomicCache;
    $ledger = new RequestLedger($store, new RequestFingerprinter('a-secret-key-of-at-least-32-bytes!!'), $clock, $store);
    $sent = $clock->now()->getTimestamp();
    $ledger->claim('A00000000', $query);

    $clock->advance(3600);

    expect($ledger->claim('A00000000', $query)?->getTimestamp())->toBe($sent);
});

it('takes a window of its own, never shorter than the 24 hours of Datadis', function () use ($query) {
    $clock = new FrozenClock;
    $atomic = new AtomicCache;
    $ledger = new RequestLedger(new InMemoryCache($clock), new RequestFingerprinter('a-secret-key-of-at-least-32-bytes!!'), $clock, windowSeconds: 86400);
    $withAtomic = new RequestLedger($atomic, new RequestFingerprinter('a-secret-key-of-at-least-32-bytes!!'), $clock, $atomic, 2 * 86400);

    $ledger->record('A00000000', $query);
    $withAtomic->claim('A00000000', $query);
    $clock->advance(86400 - 1);
    expect($ledger->lastAttempt('A00000000', $query))->not->toBeNull();

    $clock->advance(1);
    expect($ledger->lastAttempt('A00000000', $query))->toBeNull()
        ->and($withAtomic->claim('A00000000', $query))->not->toBeNull()
        ->and($atomic->ttls)->toBe([2 * 86400, 2 * 86400]);

    expect(fn () => new RequestLedger(new InMemoryCache($clock), new RequestFingerprinter('a-secret-key-of-at-least-32-bytes!!'), windowSeconds: 86399))
        ->toThrow(ConfigurationException::class, 'at least 86400');
});
