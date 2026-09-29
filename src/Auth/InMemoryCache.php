<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Auth;

use DateInterval;
use Psr\Clock\ClockInterface;
use Psr\SimpleCache\CacheInterface;

/** A process-local PSR-16 cache, the default token store. Expiry follows the injected clock. */
final class InMemoryCache implements CacheInterface
{
    /** @var array<string, array{mixed, int|null}> */
    private array $items = [];

    public function __construct(private readonly ClockInterface $clock) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->has($key) ? $this->items[$key][0] : $default;
    }

    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        $expiry = $this->expiry($ttl);

        if ($expiry !== null && $expiry <= $this->clock->now()->getTimestamp()) {
            unset($this->items[$key]);

            return true;
        }

        $this->items[$key] = [$value, $expiry];

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
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $default);
        }

        return $values;
    }

    /** @param iterable<string, mixed> $values */
    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        if (! isset($this->items[$key])) {
            return false;
        }

        $expiry = $this->items[$key][1];

        if ($expiry !== null && $expiry <= $this->clock->now()->getTimestamp()) {
            unset($this->items[$key]);

            return false;
        }

        return true;
    }

    private function expiry(DateInterval|int|null $ttl): ?int
    {
        return match (true) {
            $ttl === null => null,
            $ttl instanceof DateInterval => $this->clock->now()->add($ttl)->getTimestamp(),
            default => $this->clock->now()->getTimestamp() + $ttl,
        };
    }
}
