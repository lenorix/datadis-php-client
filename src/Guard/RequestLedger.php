<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Guard;

use DateTimeImmutable;
use Lenorix\DatadisClient\Auth\SystemClock;
use Psr\Clock\ClockInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Remembers which guarded queries were attempted in the last 24 hours, in any PSR-16 store.
 *
 * Datadis counts calls MADE, not calls that succeeded, so an attempt is recorded before the request
 * leaves and is only forgotten when the request provably never left (a failure before sending).
 * Only fingerprints and timestamps are stored. Use a store shared by every process that queries
 * with the same account, or the ledger cannot see their attempts.
 */
final class RequestLedger
{
    /** 24 hours plus a margin for clock differences with Datadis. */
    public const int WINDOW_SECONDS = 86400 + 600;

    private readonly ClockInterface $clock;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly RequestFingerprinter $fingerprinter,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock;
    }

    /** @param array<string, mixed> $query */
    public function lastAttempt(string $account, array $query): ?DateTimeImmutable
    {
        $value = $this->cache->get($this->key($account, $query));

        return is_int($value) ? (new DateTimeImmutable)->setTimestamp($value) : null;
    }

    /** @param array<string, mixed> $query */
    public function record(string $account, array $query): void
    {
        $this->cache->set($this->key($account, $query), $this->clock->now()->getTimestamp(), self::WINDOW_SECONDS);
    }

    /** @param array<string, mixed> $query */
    public function forget(string $account, array $query): void
    {
        $this->cache->delete($this->key($account, $query));
    }

    /** @param array<string, mixed> $query */
    private function key(string $account, array $query): string
    {
        // PSR-16 keys allow only [A-Za-z0-9_.] and 64 characters.
        return 'datadis_query_'.substr($this->fingerprinter->fingerprint($account, $query), 0, 48);
    }
}
