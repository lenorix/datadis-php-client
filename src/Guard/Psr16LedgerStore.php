<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Guard;

use Closure;
use Psr\SimpleCache\CacheInterface;

/**
 * Any PSR-16 cache as a ledger store. PSR-16 has no add-if-absent, so with it the ledger checks
 * and records in two steps, and two workers sending the same query at once can both go through:
 * for several workers, give the ledger an AtomicLedgerStore over the same backend instead.
 */
final class Psr16LedgerStore implements LedgerStore
{
    /**
     * Often the application's own cache, which may hold the login token too: var_export cannot show
     * what a closure holds, and __debugInfo leaves it out of var_dump and print_r.
     *
     * @var Closure(): CacheInterface
     */
    private readonly Closure $cache;

    public function __construct(CacheInterface $cache)
    {
        $this->cache = static fn (): CacheInterface => $cache;
    }

    public function get(string $key): mixed
    {
        return ($this->cache)()->get($key);
    }

    public function set(string $key, int $value, int $ttlSeconds): bool
    {
        return ($this->cache)()->set($key, $value, $ttlSeconds);
    }

    public function delete(string $key): void
    {
        ($this->cache)()->delete($key);
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['cache' => '[hidden]'];
    }
}
