<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Guard;

/**
 * Where the ledger keeps when each query was last attempted: a key of at most 64 characters
 * (`[A-Za-z0-9_.]`) and a Unix timestamp. A PSR-16 cache works as one through Psr16LedgerStore.
 *
 * Use one store shared by every process that queries with the same account, and one that is not
 * cleared on deploys: a ledger that starts empty can repeat a query sent minutes before.
 */
interface LedgerStore
{
    /** The value under $key, or null when there is none or it has expired. An int, or its digits as text. */
    public function get(string $key): mixed;

    /** Keeps $value under $key for $ttlSeconds, replacing what was there; false when it could not. */
    public function set(string $key, int $value, int $ttlSeconds): bool;

    public function delete(string $key): void;
}
