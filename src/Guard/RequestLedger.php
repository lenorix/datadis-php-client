<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Guard;

use DateTimeImmutable;
use Lenorix\DatadisClient\Auth\SystemClock;
use Lenorix\DatadisClient\Exceptions\LedgerUnavailableException;
use Psr\Clock\ClockInterface;
use Psr\SimpleCache\CacheInterface;
use SensitiveParameter;
use Throwable;

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

    /**
     * When the query was last attempted, or null if not within the window.
     *
     * The stored timestamp is checked against the clock too, so a store that ignores TTLs does
     * not block a query forever, and numeric strings are accepted because some stores return them.
     *
     * @param  array<string, mixed>  $query
     *
     * @throws LedgerUnavailableException when the store cannot be read
     */
    public function lastAttempt(string $account, #[SensitiveParameter] array $query): ?DateTimeImmutable
    {
        try {
            $value = $this->cache->get($this->key($account, $query));
        } catch (Throwable $e) {
            throw new LedgerUnavailableException('The repetition ledger store could not be read.', previous: $e);
        }

        if (is_string($value) && preg_match('/^\d{1,19}$/D', $value) === 1) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < 0 || $this->clock->now()->getTimestamp() - $value >= self::WINDOW_SECONDS) {
            return null;
        }

        return (new DateTimeImmutable)->setTimestamp($value);
    }

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws LedgerUnavailableException when the store cannot keep the record
     */
    public function record(string $account, #[SensitiveParameter] array $query): void
    {
        try {
            $stored = $this->cache->set($this->key($account, $query), $this->clock->now()->getTimestamp(), self::WINDOW_SECONDS);
        } catch (Throwable $e) {
            throw new LedgerUnavailableException('The repetition ledger store could not be written.', previous: $e);
        }

        if ($stored !== true) {
            throw new LedgerUnavailableException('The repetition ledger store refused to keep the attempt.');
        }
    }

    /** @param array<string, mixed> $query */
    public function forget(string $account, #[SensitiveParameter] array $query): void
    {
        $this->cache->delete($this->key($account, $query));
    }

    /** @param array<string, mixed> $query */
    private function key(string $account, #[SensitiveParameter] array $query): string
    {
        // PSR-16 keys allow only [A-Za-z0-9_.] and 64 characters.
        return 'datadis_query_'.substr($this->fingerprinter->fingerprint($account, $query), 0, 48);
    }
}
