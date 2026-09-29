<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Tests\Support;

use DateInterval;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;

/**
 * A PSR-16 store with the behaviours real adapters show: values read back as strings, TTL
 * ignored, set() answering false, or every call throwing because the backend is down.
 */
final class QuirkyCache implements CacheInterface
{
    /** @var array<string, mixed> */
    public array $items = [];

    public function __construct(
        public bool $throwOnGet = false,
        public bool $throwOnSet = false,
        public bool $failSet = false,
        public bool $stringify = false,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        if ($this->throwOnGet) {
            throw new RuntimeException('cache backend down');
        }

        return $this->items[$key] ?? $default;
    }

    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        if ($this->throwOnSet) {
            throw new RuntimeException('cache backend down');
        }

        if ($this->failSet) {
            return false;
        }

        $this->items[$key] = $this->stringify && is_int($value) ? (string) $value : $value;

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
        return false;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return true;
    }

    public function has(string $key): bool
    {
        return isset($this->items[$key]);
    }
}
