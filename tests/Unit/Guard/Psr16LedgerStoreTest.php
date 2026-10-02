<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Guard\Psr16LedgerStore;
use Lenorix\DatadisClient\Tests\Support\QuirkyCache;

it('keeps, reads and forgets a timestamp in any PSR-16 cache', function () {
    $store = new Psr16LedgerStore(new QuirkyCache);

    expect($store->set('k', 123, 60))->toBeTrue()->and($store->get('k'))->toBe(123);

    $store->delete('k');

    expect($store->get('k'))->toBeNull();
});

it('keeps the cache, which may hold the login token, out of every dump', function () {
    $cache = new QuirkyCache;
    $cache->set('datadis_token_x', 'a-token-never-dumped');
    $store = new Psr16LedgerStore($cache);

    ob_start();
    var_dump($store);

    expect((string) ob_get_clean().print_r($store, true).var_export($store, true))->not->toContain('a-token-never-dumped')->toContain('[hidden]');
});
