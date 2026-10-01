<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Guard;

/**
 * A store that can keep a key only if it is not there yet, in one step. PSR-16 has no such call, so
 * with a plain PSR-16 store the ledger checks and records in two steps, and two workers sending the
 * same query at the same moment can both go through. Give the ledger this too (for example
 * Laravel's `Cache::add()`, atomic on Redis, Memcached and the database store) and only one does.
 *
 * The store must expire keys after the TTL: while a key is held, the query counts as attempted.
 */
interface AtomicStore
{
    /** Keeps $value under $key for $ttlSeconds only if the key is absent or expired; true when it did. */
    public function add(string $key, mixed $value, int $ttlSeconds): bool;
}
