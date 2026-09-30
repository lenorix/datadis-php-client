<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Auth;

use Closure;
use DateInterval;
use Psr\Clock\ClockInterface;
use Psr\SimpleCache\CacheInterface;

/** A process-local PSR-16 cache, the default token store. Expiry follows the injected clock. */
final class InMemoryCache implements CacheInterface
{
    /**
     * The items live inside a closure, which var_export cannot show, and __debugInfo hides them
     * from var_dump and print_r: the cache holds a live token.
     */
    private readonly Closure $vault;

    public function __construct(private readonly ClockInterface $clock)
    {
        $items = [];
        $this->vault = static function &() use (&$items): array {
            return $items;
        };
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->has($key) ? $this->items()[$key][0] : $default;
    }

    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        $items = &$this->items();
        $expiry = $this->expiry($ttl);

        if ($expiry !== null && $expiry <= $this->clock->now()->getTimestamp()) {
            unset($items[$key]);

            return true;
        }

        $items[$key] = [$value, $expiry];

        return true;
    }

    public function delete(string $key): bool
    {
        $items = &$this->items();
        unset($items[$key]);

        return true;
    }

    public function clear(): bool
    {
        $items = &$this->items();
        $items = [];

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
        $items = &$this->items();

        if (! isset($items[$key])) {
            return false;
        }

        $expiry = $items[$key][1];

        if ($expiry !== null && $expiry <= $this->clock->now()->getTimestamp()) {
            unset($items[$key]);

            return false;
        }

        return true;
    }

    /** @return array<string, int> */
    public function __debugInfo(): array
    {
        return ['items' => count($this->items())];
    }

    /** @return array<string, array{mixed, int|null}> */
    private function &items(): array
    {
        /** @var array<string, array{mixed, int|null}> $items */
        $items = &($this->vault)();

        return $items;
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
