<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Tests\Support;

use Closure;
use Lenorix\DatadisClient\Guard\AtomicLedgerStore;
use Lenorix\DatadisClient\Guard\LedgerStore;
use RuntimeException;

/**
 * A shared ledger store, like Redis behind several workers, that can add a key only if it is
 * absent. With stale reads, every get() comes too early to see what another worker just wrote: the
 * race two workers sending the same query at once run into. Keys never expire here.
 */
final class AtomicCache implements AtomicLedgerStore
{
    /** @var array<string, int> */
    public array $items = [];

    /** @var list<int> the TTL of every add() */
    public array $ttls = [];

    /** @param  (Closure(self): void)|null  $beforeAdd  runs before each add(), as another worker would */
    public function __construct(public bool $staleReads = false, public bool $failAdd = false, public ?Closure $beforeAdd = null) {}

    public function add(string $key, int $value, int $ttlSeconds): bool
    {
        if ($this->failAdd) {
            throw new RuntimeException('cache backend down');
        }

        $this->ttls[] = $ttlSeconds;

        if ($this->beforeAdd !== null) {
            ($this->beforeAdd)($this);
        }

        if (array_key_exists($key, $this->items)) {
            return false;
        }

        $this->items[$key] = $value;

        return true;
    }

    public function get(string $key): mixed
    {
        return $this->staleReads ? null : ($this->items[$key] ?? null);
    }

    public function set(string $key, int $value, int $ttlSeconds): bool
    {
        $this->items[$key] = $value;

        return true;
    }

    public function delete(string $key): void
    {
        unset($this->items[$key]);
    }

    /** The same store without add(), as a plain store over the same backend would be. */
    public function withoutAdd(): LedgerStore
    {
        return new class($this) implements LedgerStore
        {
            public function __construct(private AtomicCache $store) {}

            public function get(string $key): mixed
            {
                return $this->store->get($key);
            }

            public function set(string $key, int $value, int $ttlSeconds): bool
            {
                return $this->store->set($key, $value, $ttlSeconds);
            }

            public function delete(string $key): void
            {
                $this->store->delete($key);
            }
        };
    }
}
