<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Guard;

use DateTimeImmutable;
use Lenorix\DatadisClient\Exceptions\LedgerUnavailableException;
use Lenorix\DatadisClient\Support\SystemClock;
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

    /**
     * How far ahead of this clock a stored time may be and still count: another worker's clock can
     * run a little fast. A time further ahead is corrupt and would block the query for longer than
     * the window, so it is ignored.
     */
    public const int CLOCK_TOLERANCE_SECONDS = 600;

    private readonly ClockInterface $clock;

    /**
     * @param  AtomicStore|null  $atomic  the same store, able to add a key only if absent: then checking
     *                                    and recording are one step and concurrent workers cannot both send
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly RequestFingerprinter $fingerprinter,
        ?ClockInterface $clock = null,
        private readonly ?AtomicStore $atomic = null,
    ) {
        $this->clock = $clock ?? new SystemClock;
    }

    /**
     * When the query was last attempted, or null if not within the window.
     *
     * The stored timestamp is checked against the clock too, so a store that ignores TTLs does
     * not block a query forever, nor a time far in the future for longer than the window. Numeric
     * strings are accepted because some stores return them.
     *
     * @param  array<string, string|int|list<string>|null>  $query
     *
     * @throws LedgerUnavailableException when the store cannot be read
     */
    public function lastAttempt(string $account, #[SensitiveParameter] array $query): ?DateTimeImmutable
    {
        return $this->attemptIn($this->read($account, $query));
    }

    /**
     * @param  array<string, string|int|list<string>|null>  $query
     *
     * @throws LedgerUnavailableException when the store cannot be read
     */
    private function read(string $account, #[SensitiveParameter] array $query): mixed
    {
        try {
            return $this->cache->get($this->key($account, $query));
        } catch (Throwable $e) {
            throw new LedgerUnavailableException('The repetition ledger store could not be read.', previous: $e);
        }
    }

    /** The attempt a stored value stands for, or null when it does not count. */
    private function attemptIn(mixed $value): ?DateTimeImmutable
    {
        if (is_string($value) && preg_match('/^\d{1,19}$/D', $value) === 1) {
            $value = (int) $value;
        }

        $elapsed = is_int($value) ? $this->clock->now()->getTimestamp() - $value : null;

        if ($elapsed === null || $elapsed >= self::WINDOW_SECONDS || $elapsed < -self::CLOCK_TOLERANCE_SECONDS) {
            return null;
        }

        return (new DateTimeImmutable)->setTimestamp($value);
    }

    /**
     * Records the attempt unless the query was already attempted in the window: null when it may
     * be sent now, or the time of the earlier attempt. With an AtomicStore this is a single step.
     *
     * @param  array<string, string|int|list<string>|null>  $query
     *
     * @throws LedgerUnavailableException when the store cannot be read or written
     */
    public function claim(string $account, #[SensitiveParameter] array $query): ?DateTimeImmutable
    {
        if ($this->atomic === null) {
            $last = $this->lastAttempt($account, $query);

            if ($last === null) {
                $this->record($account, $query);
            }

            return $last;
        }

        $now = $this->clock->now()->getTimestamp();

        if ($this->add($this->atomic, $account, $query, $now)) {
            return null;
        }

        // Another worker holds the key. A held key always counts, even when its value cannot be read
        // yet or does not make sense: taking it back would be a delete and an add, two steps another
        // worker could slip between. The store's TTL frees it within the window. Without a time, it is now.
        return $this->lastAttempt($account, $query) ?? (new DateTimeImmutable)->setTimestamp($now);
    }

    /** @param  array<string, string|int|list<string>|null>  $query */
    private function add(AtomicStore $atomic, string $account, #[SensitiveParameter] array $query, int $now): bool
    {
        try {
            return $atomic->add($this->key($account, $query), $now, self::WINDOW_SECONDS);
        } catch (Throwable $e) {
            throw new LedgerUnavailableException('The repetition ledger store could not be written.', previous: $e);
        }
    }

    /**
     * @param  array<string, string|int|list<string>|null>  $query
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

    /**
     * @param  array<string, string|int|list<string>|null>  $query
     *
     * @throws LedgerUnavailableException when the store cannot remove the record
     */
    public function forget(string $account, #[SensitiveParameter] array $query): void
    {
        try {
            $this->cache->delete($this->key($account, $query));
        } catch (Throwable $e) {
            throw new LedgerUnavailableException('The repetition ledger store could not be written.', previous: $e);
        }
    }

    /** @param array<string, string|int|list<string>|null> $query */
    private function key(string $account, #[SensitiveParameter] array $query): string
    {
        // PSR-16 keys allow only [A-Za-z0-9_.] and 64 characters.
        return 'datadis_query_'.substr($this->fingerprinter->fingerprint($account, $query), 0, 48);
    }
}
