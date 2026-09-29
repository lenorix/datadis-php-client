<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Auth\InMemoryCache;
use Lenorix\DatadisClient\Guard\RequestFingerprinter;
use Lenorix\DatadisClient\Guard\RequestLedger;
use Lenorix\DatadisClient\Tests\Support\FrozenClock;

$query = ['cups' => 'ES0031300000000001JN0F', 'distributorCode' => '2', 'startDate' => '2026/01', 'endDate' => '2026/01'];

function ledger(FrozenClock $clock): RequestLedger
{
    return new RequestLedger(new InMemoryCache($clock), new RequestFingerprinter('a-secret-key-of-at-least-32-bytes!!'), $clock);
}

it('remembers an attempt for the whole repetition window', function () use ($query) {
    $clock = new FrozenClock;
    $ledger = ledger($clock);

    expect($ledger->lastAttempt('12345678Z', $query))->toBeNull();

    $ledger->record('12345678Z', $query);
    expect($ledger->lastAttempt('12345678Z', $query)?->getTimestamp())->toBe($clock->now()->getTimestamp());

    $clock->advance(RequestLedger::WINDOW_SECONDS - 1);
    expect($ledger->lastAttempt('12345678Z', $query))->not->toBeNull();

    $clock->advance(1);
    expect($ledger->lastAttempt('12345678Z', $query))->toBeNull();
});

it('keeps a safety margin beyond 24 hours', function () {
    expect(RequestLedger::WINDOW_SECONDS)->toBeGreaterThan(86400);
});

it('forgets an attempt on demand', function () use ($query) {
    $ledger = ledger(new FrozenClock);
    $ledger->record('12345678Z', $query);
    $ledger->forget('12345678Z', $query);

    expect($ledger->lastAttempt('12345678Z', $query))->toBeNull();
});

it('keeps accounts apart', function () use ($query) {
    $ledger = ledger(new FrozenClock);
    $ledger->record('12345678Z', $query);

    expect($ledger->lastAttempt('87654321X', $query))->toBeNull();
});

it('stores only a hash, never the CUPS', function () use ($query) {
    $clock = new FrozenClock;
    $cache = new InMemoryCache($clock);
    (new RequestLedger($cache, new RequestFingerprinter('a-secret-key-of-at-least-32-bytes!!'), $clock))->record('12345678Z', $query);

    expect(print_r($cache, true))->not->toContain('ES0031300000000001JN0F')->not->toContain('12345678Z');
});
