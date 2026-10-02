<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Guard;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Lenorix\DatadisClient\Exceptions\InvalidRequestException;
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
    /** The default window: 24 hours plus a margin for clock differences with Datadis. */
    public const int WINDOW_SECONDS = 86400 + 600;

    /** Datadis's own window: a shorter one would let through queries Datadis refuses and counts. */
    public const int MIN_WINDOW_SECONDS = 86400;

    /**
     * How far ahead of this clock a stored time may be and still count: another worker's clock can
     * run a little fast. A time further ahead is corrupt and would block the query for longer than
     * the window, so it is ignored.
     */
    public const int CLOCK_TOLERANCE_SECONDS = 600;

    private readonly ClockInterface $clock;

    private readonly int $windowSeconds;

    /**
     * The store is often the application's own cache, which may also hold the login token and show
     * its values when dumped. var_export cannot show what a closure holds, and __debugInfo leaves it
     * out of var_dump and print_r.
     *
     * @var Closure(): LedgerStore
     */
    private readonly Closure $store;

    private readonly bool $atomic;

    /** @var (Closure(LedgerEvent): void)|null */
    private readonly ?Closure $onChange;

    /**
     * @param  LedgerStore|CacheInterface  $store  one store for every read and write; a PSR-16 cache is
     *                                             wrapped in Psr16LedgerStore. An AtomicLedgerStore makes
     *                                             checking and recording one step, so concurrent workers
     *                                             cannot both send
     * @param  (Closure(LedgerEvent): void)|null  $onChange  told of every query claimed, released or remembered, to keep a
     *                                                       history; what it throws is ignored, so it never decides whether
     *                                                       a query goes
     * @param  int|null  $windowSeconds  how long an attempt blocks the same query, at least 24 hours;
     *                                   WINDOW_SECONDS by default. A sync that runs every day should vary
     *                                   its ranges (MonthPlanner::latest()) rather than shorten this.
     *
     * @throws ConfigurationException when the window is shorter than 24 hours
     */
    public function __construct(
        LedgerStore|CacheInterface $store,
        private readonly RequestFingerprinter $fingerprinter,
        ?ClockInterface $clock = null,
        ?int $windowSeconds = null,
        ?Closure $onChange = null,
    ) {
        $this->onChange = $onChange;
        if ($windowSeconds !== null && $windowSeconds < self::MIN_WINDOW_SECONDS) {
            throw new ConfigurationException('The repetition window must be at least '.self::MIN_WINDOW_SECONDS." seconds (24 hours), {$windowSeconds} given: Datadis refuses a repeat within 24 hours and counts it.");
        }

        $this->windowSeconds = $windowSeconds ?? self::WINDOW_SECONDS;
        $store = $store instanceof LedgerStore ? $store : new Psr16LedgerStore($store);
        $this->store = static fn (): LedgerStore => $store;
        $this->atomic = $store instanceof AtomicLedgerStore;
        $this->clock = $clock ?? new SystemClock;
    }

    /** How long an attempt blocks the same query, in seconds. */
    public function windowSeconds(): int
    {
        return $this->windowSeconds;
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
    public function lastAttempt(#[SensitiveParameter] string $account, #[SensitiveParameter] array $query): ?DateTimeImmutable
    {
        return $this->attemptIn($this->read($account, $query));
    }

    /**
     * @param  array<string, string|int|list<string>|null>  $query
     *
     * @throws LedgerUnavailableException when the store cannot be read
     */
    private function read(#[SensitiveParameter] string $account, #[SensitiveParameter] array $query): mixed
    {
        try {
            return ($this->store)()->get($this->key($account, $query));
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

        if ($elapsed === null || $elapsed >= $this->windowSeconds || $elapsed < -self::CLOCK_TOLERANCE_SECONDS) {
            return null;
        }

        return (new DateTimeImmutable)->setTimestamp($value);
    }

    /**
     * Records the attempt unless the query was already attempted in the window: null when it may
     * be sent now, or the time of the earlier attempt. With an AtomicLedgerStore this is a single step.
     *
     * @param  array<string, string|int|list<string>|null>  $query
     *
     * @throws LedgerUnavailableException when the store cannot be read or written
     */
    public function claim(#[SensitiveParameter] string $account, #[SensitiveParameter] array $query, ?string $endpoint = null): ?DateTimeImmutable
    {
        if (! $this->atomic) {
            $last = $this->lastAttempt($account, $query);

            if ($last === null) {
                $this->record($account, $query);
                $this->tell(LedgerEventKind::Claimed, $account, $query, $this->clock->now()->getTimestamp(), $endpoint);
            }

            return $last;
        }

        $now = $this->clock->now()->getTimestamp();

        if ($this->add($account, $query, $now, $this->windowSeconds)) {
            $this->tell(LedgerEventKind::Claimed, $account, $query, $now, $endpoint);

            return null;
        }

        // Another worker holds the key. A held key always counts, even when its value cannot be read
        // yet or does not make sense: taking it back would be a delete and an add, two steps another
        // worker could slip between. The store's TTL frees it within the window. Without a time, it is now.
        return $this->lastAttempt($account, $query) ?? (new DateTimeImmutable)->setTimestamp($now);
    }

    /** @param  array<string, string|int|list<string>|null>  $query */
    private function add(#[SensitiveParameter] string $account, #[SensitiveParameter] array $query, int $at, int $ttl): bool
    {
        $store = ($this->store)();
        assert($store instanceof AtomicLedgerStore);

        try {
            return $store->add($this->key($account, $query), $at, $ttl);
        } catch (Throwable $e) {
            throw new LedgerUnavailableException('The repetition ledger store could not be written.', previous: $e);
        }
    }

    /**
     * @param  array<string, string|int|list<string>|null>  $query
     *
     * @throws LedgerUnavailableException when the store cannot keep the record
     */
    public function record(#[SensitiveParameter] string $account, #[SensitiveParameter] array $query): void
    {
        $this->store($account, $query, $this->clock->now()->getTimestamp(), $this->windowSeconds);
    }

    /** @param  array<string, string|int|list<string>|null>  $query */
    private function store(#[SensitiveParameter] string $account, #[SensitiveParameter] array $query, int $at, int $ttl): void
    {
        try {
            $stored = ($this->store)()->set($this->key($account, $query), $at, $ttl);
        } catch (Throwable $e) {
            throw new LedgerUnavailableException('The repetition ledger store could not be written.', previous: $e);
        }

        if ($stored !== true) {
            throw new LedgerUnavailableException('The repetition ledger store refused to keep the attempt.');
        }
    }

    /**
     * Records an attempt made earlier, when this ledger did not keep the record yet. The query must
     * be the one the guard keys on (for maximum power and reactive data, without `authorizedNif`):
     * the client's remember...() methods build it, and are the way to use this. It is kept for
     * what is left of its window, so a query sent 23 hours ago blocks a repeat for little more than
     * an hour, and an attempt older than the window is not recorded. The newest attempt of a query
     * wins, whatever order a history is given in. Import with the workers paused: an import that
     * overwrites an older attempt is not a single step.
     *
     * @param  array<string, string|int|list<string>|null>  $query
     * @return bool whether it was recorded
     *
     * @throws InvalidRequestException when the attempt is further in the future than the clock tolerance
     * @throws LedgerUnavailableException when the store cannot be read or written
     */
    public function rememberAt(#[SensitiveParameter] string $account, #[SensitiveParameter] array $query, DateTimeInterface $sentAt, ?string $endpoint = null): bool
    {
        $at = $sentAt->getTimestamp();
        $age = $this->clock->now()->getTimestamp() - $at;

        if ($age < -self::CLOCK_TOLERANCE_SECONDS) {
            throw new InvalidRequestException('An attempt cannot have been made in the future.');
        }

        if ($age >= $this->windowSeconds) {
            return false;
        }

        // Its own window, not a whole one from now: a longer life would block the query after Datadis takes it.
        $ttl = $this->windowSeconds - $age;
        $last = $this->lastAttempt($account, $query);

        if ($last !== null && $last->getTimestamp() >= $at) {
            return false;
        }

        if ($last === null && $this->atomic && $this->add($account, $query, $at, $ttl)) {
            $this->tell(LedgerEventKind::Remembered, $account, $query, $at, $endpoint);

            return true;
        }

        // Held by an older attempt, or taken just now by a worker that sent: only an older one is replaced.
        $last = $this->lastAttempt($account, $query);

        if ($last !== null && $last->getTimestamp() >= $at) {
            return false;
        }

        $this->store($account, $query, $at, $ttl);
        $this->tell(LedgerEventKind::Remembered, $account, $query, $at, $endpoint);

        return true;
    }

    /**
     * @param  array<string, string|int|list<string>|null>  $query
     *
     * @throws LedgerUnavailableException when the store cannot remove the record
     */
    public function forget(#[SensitiveParameter] string $account, #[SensitiveParameter] array $query, ?string $endpoint = null): void
    {
        $key = $this->key($account, $query);
        $now = $this->clock->now()->getTimestamp();

        try {
            // A store that could not delete gets an attempt already outside the window, living one
            // second: free at once for a plain store, which reads the time, and within a second for
            // an atomic one, which counts a held key whatever its time.
            $freed = ($this->store)()->delete($key) || ($this->store)()->set($key, $now - $this->windowSeconds, 1);
        } catch (Throwable $e) {
            throw new LedgerUnavailableException('The repetition ledger store could not be written.', previous: $e);
        }

        if (! $freed) {
            throw new LedgerUnavailableException('The repetition ledger store could not free the query.');
        }

        $this->tell(LedgerEventKind::Released, $account, $query, $now, $endpoint);
    }

    /** @param  array<string, string|int|list<string>|null>  $query */
    private function tell(LedgerEventKind $kind, #[SensitiveParameter] string $account, #[SensitiveParameter] array $query, int $at, ?string $endpoint): void
    {
        if ($this->onChange === null) {
            return;
        }

        try {
            ($this->onChange)(new LedgerEvent($kind, $this->key($account, $query), (new DateTimeImmutable)->setTimestamp($at), $endpoint));
        } catch (Throwable) {
            // A history that fails must not decide whether a query goes, nor hide why it did not.
        }
    }

    /** @param array<string, string|int|list<string>|null> $query */
    private function key(#[SensitiveParameter] string $account, #[SensitiveParameter] array $query): string
    {
        // PSR-16 keys allow only [A-Za-z0-9_.] and 64 characters.
        return 'datadis_query_'.substr($this->fingerprinter->fingerprint($account, $query), 0, 48);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['store' => '[hidden]', 'atomic' => $this->atomic];
    }
}
