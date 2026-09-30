<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Support\InMemoryCache;
use Lenorix\DatadisClient\Tests\Support\FrozenClock;

it('keeps values without a ttl until they are deleted', function () {
    $clock = new FrozenClock;
    $cache = new InMemoryCache($clock);

    expect($cache->set('a', 1))->toBeTrue();
    $clock->advance(10 ** 8);

    expect($cache->get('a'))->toBe(1)->and($cache->has('a'))->toBeTrue()
        ->and($cache->delete('a'))->toBeTrue()
        ->and($cache->get('a', 'default'))->toBe('default');
});

it('expires values by seconds or by interval, following the clock', function (int|DateInterval $ttl) {
    $clock = new FrozenClock;
    $cache = new InMemoryCache($clock);
    $cache->set('a', 'value', $ttl);

    $clock->advance(59);
    expect($cache->has('a'))->toBeTrue();

    $clock->advance(1);
    expect($cache->has('a'))->toBeFalse()->and($cache->get('a'))->toBeNull();
})->with([[60], [new DateInterval('PT60S')]]);

it('does not store a value that is already expired, and removes an older one', function () {
    $cache = new InMemoryCache(new FrozenClock);
    $cache->set('a', 'old');

    expect($cache->set('a', 'new', 0))->toBeTrue()->and($cache->has('a'))->toBeFalse();
});

it('handles several keys at once and clears everything', function () {
    $cache = new InMemoryCache(new FrozenClock);

    expect($cache->setMultiple(['a' => 1, 'b' => 2]))->toBeTrue()
        ->and($cache->getMultiple(['a', 'b', 'c'], 'none'))->toBe(['a' => 1, 'b' => 2, 'c' => 'none'])
        ->and($cache->deleteMultiple(['a']))->toBeTrue()
        ->and($cache->has('a'))->toBeFalse()
        ->and($cache->clear())->toBeTrue()
        ->and($cache->has('b'))->toBeFalse();
});
