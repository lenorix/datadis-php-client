<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Guard;

/**
 * A ledger store that can keep a key only if it is not there yet, in one step (Redis `SET NX`, an
 * insert on a unique key, Laravel's `Cache::add()`). Then checking and recording a query are one
 * step, and two workers sending the same query at the same moment cannot both go through.
 *
 * add() must treat an expired entry as absent: while a key is held, the query counts as attempted,
 * so a key that never expires (a plain insert into a table, say) blocks its query for good. In a
 * table, insert the row, or replace it when its expiry has passed, in one statement.
 */
interface AtomicLedgerStore extends LedgerStore
{
    /** Keeps $value under $key for $ttlSeconds only if the key is absent or expired; true when it did. */
    public function add(string $key, int $value, int $ttlSeconds): bool;
}
