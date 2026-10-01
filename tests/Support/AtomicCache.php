<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Tests\Support;

use DateInterval;
use Lenorix\DatadisClient\Guard\AtomicStore;
use Psr\SimpleCache\CacheInterface;

/**
 * A shared store, like Redis behind several workers, that can add a key only if it is absent. With
 * stale reads, every get() comes too early to see what another worker just wrote: the race two
 * workers sending the same query at once run into.
 */
final class AtomicCache implements AtomicStore, CacheInterface
{
    /** @var array<string, mixed> */
    public array $items = [];

    public function __construct(public bool $staleReads = false, public bool $failAdd = false) {}

    public function add(string $key, mixed $value, int $ttlSeconds): bool
    {
        if ($this->failAdd) {
            throw new \RuntimeException('cache backend down');
        }

        if (array_key_exists($key, $this->items)) {
            return false;
        }

        $this->items[$key] = $value;

        return true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->staleReads ? $default : ($this->items[$key] ?? $default);
    }

    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        $this->items[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->items[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->items = [];

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        return [];
    }

    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return true;
    }

    public function has(string $key): bool
    {
        return ! $this->staleReads && array_key_exists($key, $this->items);
    }
}
