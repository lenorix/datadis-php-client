<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Lenorix\DatadisClient\Exceptions\InvalidRequestException;
use Lenorix\DatadisClient\Exceptions\LedgerUnavailableException;
use Lenorix\DatadisClient\Guard\AtomicLedgerStore;
use Lenorix\DatadisClient\Guard\LedgerEventKind;
use Lenorix\DatadisClient\Guard\RequestFingerprinter;
use Lenorix\DatadisClient\Guard\RequestLedger;
use Lenorix\DatadisClient\Support\InMemoryCache;
use Lenorix\DatadisClient\Tests\Support\AtomicCache;
use Lenorix\DatadisClient\Tests\Support\FrozenClock;
use Lenorix\DatadisClient\Tests\Support\QuirkyCache;
use Lenorix\DatadisClient\Tests\Support\Scenario;

$query = ['cups' => 'ES0000000000000000AA0A', 'distributorCode' => '2', 'startDate' => '2026/01', 'endDate' => '2026/01'];

it('remembers an attempt for 24 hours and a margin for clock differences with Datadis', function () use ($query) {
    $clock = new FrozenClock;
    $ledger = Scenario::ledger($clock);

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
    $ledger = Scenario::ledger(new FrozenClock);
    $ledger->record('A00000000', $query);
    $ledger->forget('A00000000', $query);

    expect($ledger->lastAttempt('A00000000', $query))->toBeNull();
});

it('keeps accounts apart', function () use ($query) {
    $ledger = Scenario::ledger(new FrozenClock);
    $ledger->record('A00000000', $query);

    expect($ledger->lastAttempt('00000000T', $query))->toBeNull();
});

it('stores only a hash, never the CUPS', function () use ($query) {
    $clock = new FrozenClock;
    $cache = new InMemoryCache($clock);
    (new RequestLedger($cache, new RequestFingerprinter(Scenario::SECRET), $clock))->record('A00000000', $query);

    expect(print_r($cache, true))->not->toContain('ES0000000000000000AA0A')->not->toContain('A00000000');
});

it('reads a timestamp that the store gives back as a string', function () use ($query) {
    $clock = new FrozenClock;
    $ledger = new RequestLedger(new QuirkyCache(stringify: true), new RequestFingerprinter(Scenario::SECRET), $clock);
    $ledger->record('A00000000', $query);

    expect($ledger->lastAttempt('A00000000', $query)?->getTimestamp())->toBe($clock->now()->getTimestamp());
});

it('does not block forever when the store ignores the TTL', function () use ($query) {
    $clock = new FrozenClock;
    $ledger = new RequestLedger(new QuirkyCache, new RequestFingerprinter(Scenario::SECRET), $clock);
    $ledger->record('A00000000', $query);

    $clock->advance(RequestLedger::WINDOW_SECONDS);

    expect($ledger->lastAttempt('A00000000', $query))->toBeNull();
});

it('ignores values it did not write', function (mixed $value) use ($query) {
    $cache = new QuirkyCache;
    $ledger = new RequestLedger($cache, new RequestFingerprinter(Scenario::SECRET), new FrozenClock);
    $ledger->record('A00000000', $query);
    foreach (array_keys($cache->items) as $key) {
        $cache->items[$key] = $value;
    }

    expect($ledger->lastAttempt('A00000000', $query))->toBeNull();
})->with([['yesterday'], [null], [['x']], ['-5'], [1.5]]);

it('fails loudly when the store cannot be read or written', function (QuirkyCache $cache, Closure $use) use ($query) {
    $ledger = new RequestLedger($cache, new RequestFingerprinter(Scenario::SECRET), new FrozenClock);

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
    (new RequestLedger($cache, new RequestFingerprinter(Scenario::SECRET), new FrozenClock))->record('A00000000', $query);

    expect(array_keys($cache->items)[0])->toMatch('/^[A-Za-z0-9_.]{1,64}$/');
});

it('takes a time a little ahead as a recent attempt, but one far in the future as a value it did not write', function (int $ahead, bool $blocks) use ($query) {
    $clock = new FrozenClock;
    $cache = new QuirkyCache;
    $ledger = new RequestLedger($cache, new RequestFingerprinter(Scenario::SECRET), $clock);
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
    $ledger = new RequestLedger($store, new RequestFingerprinter(Scenario::SECRET), $clock);
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
    $ledger = new RequestLedger($store, new RequestFingerprinter(Scenario::SECRET), $clock);
    $sent = $clock->now()->getTimestamp();
    $ledger->claim('A00000000', $query);

    $clock->advance(3600);

    expect($ledger->claim('A00000000', $query)?->getTimestamp())->toBe($sent);
});

it('takes a window of its own, never shorter than the 24 hours of Datadis', function () use ($query) {
    $clock = new FrozenClock;
    $atomic = new AtomicCache;
    $ledger = new RequestLedger(new InMemoryCache($clock), new RequestFingerprinter(Scenario::SECRET), $clock, windowSeconds: 86400);
    $withAtomic = new RequestLedger($atomic, new RequestFingerprinter(Scenario::SECRET), $clock, 2 * 86400);

    $ledger->record('A00000000', $query);
    $withAtomic->claim('A00000000', $query);
    $clock->advance(86400 - 1);
    expect($ledger->lastAttempt('A00000000', $query))->not->toBeNull();

    $clock->advance(1);
    expect($ledger->lastAttempt('A00000000', $query))->toBeNull()
        ->and($withAtomic->claim('A00000000', $query))->not->toBeNull()
        ->and($atomic->ttls)->toBe([2 * 86400, 2 * 86400]);

    expect(fn () => new RequestLedger(new InMemoryCache($clock), new RequestFingerprinter(Scenario::SECRET), windowSeconds: 86399))
        ->toThrow(ConfigurationException::class, 'at least 86400');
});

it('remembers an earlier attempt for what is left of its window, in a plain or an atomic store', function (bool $atomic) use ($query) {
    $clock = new FrozenClock;
    $store = $atomic ? new AtomicCache : new InMemoryCache($clock);
    $ledger = new RequestLedger($store, new RequestFingerprinter(Scenario::SECRET), $clock);
    $sentAt = $clock->now()->modify('-23 hours');

    expect($ledger->rememberAt('A00000000', $query, $sentAt))->toBeTrue()
        ->and($ledger->lastAttempt('A00000000', $query)?->getTimestamp())->toBe($sentAt->getTimestamp());

    if ($atomic) {
        expect($store->ttls)->toBe([RequestLedger::WINDOW_SECONDS - 23 * 3600]);

        return;
    }

    $clock->advance(RequestLedger::WINDOW_SECONDS - 23 * 3600);
    expect($ledger->lastAttempt('A00000000', $query))->toBeNull();
})->with(['plain' => [false], 'atomic' => [true]]);

it('replaces only an older attempt, also one held in an atomic store', function () use ($query) {
    $clock = new FrozenClock;
    $store = new AtomicCache;
    $ledger = new RequestLedger($store, new RequestFingerprinter(Scenario::SECRET), $clock);

    $ledger->claim('A00000000', $query);
    $clock->advance(3600);

    expect($ledger->rememberAt('A00000000', $query, $clock->now()->modify('-2 hours')))->toBeFalse()
        ->and($ledger->rememberAt('A00000000', $query, $clock->now()->modify('-10 minutes')))->toBeTrue()
        ->and($ledger->lastAttempt('A00000000', $query)?->getTimestamp())->toBe($clock->now()->getTimestamp() - 600);
});

it('leaves a held key whose time cannot be read, since it may be a worker\'s claim just sent', function () use ($query) {
    $clock = new FrozenClock;
    $store = new AtomicCache(staleReads: true);
    $ledger = new RequestLedger($store, new RequestFingerprinter(Scenario::SECRET), $clock);
    $ledger->claim('A00000000', $query);

    // Shortening it to an attempt 23 hours old would free the query about 22 hours early.
    expect($ledger->rememberAt('A00000000', $query, $clock->now()->modify('-23 hours')))->toBeFalse()
        ->and(array_values($store->items))->toBe([$clock->now()->getTimestamp()]);
});

it('refuses an attempt further in the future than the clock tolerance, and takes one within it', function () use ($query) {
    $clock = new FrozenClock;
    $ledger = Scenario::ledger($clock);

    expect(fn () => $ledger->rememberAt('A00000000', $query, $clock->now()->modify('+'.(RequestLedger::CLOCK_TOLERANCE_SECONDS + 1).' seconds')))->toThrow(InvalidRequestException::class)
        ->and($ledger->rememberAt('A00000000', $query, $clock->now()->modify('+'.RequestLedger::CLOCK_TOLERANCE_SECONDS.' seconds')))->toBeTrue();
});

it('reports a store that refuses to keep a remembered attempt', function () use ($query) {
    $clock = new FrozenClock;
    $ledger = new RequestLedger(new QuirkyCache(failSet: true), new RequestFingerprinter(Scenario::SECRET), $clock);

    $ledger->rememberAt('A00000000', $query, $clock->now()->modify('-1 hour'));
})->throws(LedgerUnavailableException::class);

it('leaves alone a query a worker sent while the earlier attempt was being remembered', function () use ($query) {
    $clock = new FrozenClock;
    $store = new AtomicCache;
    $ledger = new RequestLedger($store, new RequestFingerprinter(Scenario::SECRET), $clock);
    // Between the import's read and its add, a worker sends the query now.
    $store->beforeAdd = function (AtomicCache $cache) use ($ledger, $query): void {
        $cache->beforeAdd = null;
        $ledger->claim('A00000000', $query);
    };

    expect($ledger->rememberAt('A00000000', $query, $clock->now()->modify('-1 hour')))->toBeFalse()
        ->and($ledger->lastAttempt('A00000000', $query)?->getTimestamp())->toBe($clock->now()->getTimestamp());
});

it('frees a query whose key the store could not delete, by an attempt already outside the window', function () use ($query) {
    $clock = new FrozenClock;
    $ledger = new RequestLedger(new QuirkyCache(failDelete: true), new RequestFingerprinter(Scenario::SECRET), $clock);

    $ledger->record('A00000000', $query);
    $ledger->forget('A00000000', $query);

    expect($ledger->lastAttempt('A00000000', $query))->toBeNull()
        ->and($ledger->claim('A00000000', $query))->toBeNull();
});

it('reports a store that can neither delete nor overwrite the key', function () use ($query) {
    $clock = new FrozenClock;
    $cache = new QuirkyCache(failDelete: true);
    $ledger = new RequestLedger($cache, new RequestFingerprinter(Scenario::SECRET), $clock);
    $ledger->record('A00000000', $query);
    $cache->failSet = true;

    expect(fn () => $ledger->forget('A00000000', $query))->toThrow(LedgerUnavailableException::class, 'could not free the query')
        ->and($ledger->lastAttempt('A00000000', $query))->not->toBeNull();
});

it('takes a key that frees itself while being refused, instead of blocking it for a whole window', function () use ($query) {
    $clock = new FrozenClock;
    $store = new AtomicCache;
    $ledger = new RequestLedger($store, new RequestFingerprinter(Scenario::SECRET), $clock);
    $ledger->claim('A00000000', $query);
    // A release that could not delete leaves the key held, with a time already outside the window,
    // until it expires a second later: here, between the first add and the second.
    $key = array_key_first($store->items);
    $store->items[$key] = $clock->now()->getTimestamp() - RequestLedger::WINDOW_SECONDS;
    $adds = 0;
    $store->beforeAdd = function (AtomicCache $s) use (&$adds, $key) {
        if (++$adds === 2) {
            unset($s->items[$key]);
        }
    };

    expect($ledger->claim('A00000000', $query))->toBeNull()
        ->and($store->items[$key])->toBe($clock->now()->getTimestamp());
});

it('refuses and tells of it when another worker holds the key and the store cannot read it', function () use ($query) {
    $clock = new FrozenClock;
    $kinds = [];
    $store = new class implements AtomicLedgerStore
    {
        public function add(string $key, int $value, int $ttlSeconds): bool
        {
            return false;
        }

        public function get(string $key): mixed
        {
            throw new RuntimeException('read timed out');
        }

        public function set(string $key, int $value, int $ttlSeconds): bool
        {
            return true;
        }

        public function delete(string $key): bool
        {
            return true;
        }
    };
    $ledger = new RequestLedger($store, new RequestFingerprinter(Scenario::SECRET), $clock, onChange: function ($e) use (&$kinds) {
        $kinds[] = $e->kind;
    });

    expect($ledger->claim('A00000000', $query)?->getTimestamp())->toBe($clock->now()->getTimestamp())
        ->and($kinds)->toBe([LedgerEventKind::Refused]);
});

it('refuses a window longer than 30 days, a mistake in the settings', function () {
    $clock = new FrozenClock;

    expect(fn () => new RequestLedger(new InMemoryCache($clock), new RequestFingerprinter(Scenario::SECRET), $clock, PHP_INT_MAX))
        ->toThrow(ConfigurationException::class, 'at most')
        ->and(new RequestLedger(new InMemoryCache($clock), new RequestFingerprinter(Scenario::SECRET), $clock, RequestLedger::MAX_WINDOW_SECONDS))->toBeInstanceOf(RequestLedger::class);
});
