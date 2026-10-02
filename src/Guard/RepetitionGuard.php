<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Guard;

use Closure;
use DateTimeInterface;
use Lenorix\DatadisClient\Exceptions\DatadisException;
use Lenorix\DatadisClient\Exceptions\InvalidRequestException;
use Lenorix\DatadisClient\Exceptions\LedgerUnavailableException;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\DatadisClient\Http\Endpoint;
use SensitiveParameter;
use Throwable;

/**
 * Refuses locally a guarded query already attempted in the last 24 hours, records each attempt
 * before it is sent, and forgets it again only when it certainly never left.
 *
 * @internal
 */
final readonly class RepetitionGuard
{
    /**
     * The account NIF, kept out of dumps of the client: var_export cannot show what a closure holds,
     * and __debugInfo leaves it out of var_dump and print_r.
     *
     * @var Closure(): string
     */
    private Closure $account;

    public function __construct(private RequestLedger $ledger, #[SensitiveParameter] string $account)
    {
        $this->account = static fn (): string => $account;
    }

    /**
     * @template T
     *
     * @param  array<string, string|int|list<string>|null>  $query
     * @param  Closure(): T  $send
     * @return T
     */
    public function call(Endpoint $endpoint, string $name, #[SensitiveParameter] array $query, #[SensitiveParameter] Closure $send): mixed
    {
        if (! $endpoint->isGuarded()) {
            return $send();
        }

        $key = self::repetitionKey($endpoint, $query);

        try {
            $last = $this->ledger->claim(($this->account)(), $key);
        } catch (LedgerUnavailableException $e) {
            throw new LedgerUnavailableException("{$name}: {$e->getMessage()}", $name, $e);
        }

        if ($last !== null) {
            $availableAt = $last->modify('+'.$this->ledger->windowSeconds().' seconds');

            throw new RepetitionWindowException(
                "{$name}: the same query was already sent at {$last->format(DATE_ATOM)}; Datadis refuses repeating it within 24 hours, so it is allowed again from {$availableAt->format(DATE_ATOM)}.",
                endpoint: $name,
                requestSent: false,
                lastAttemptAt: $last,
                availableAt: $availableAt,
            );
        }

        try {
            return $send();
        } catch (DatadisException $e) {
            if (! $e->requestSent) {
                try {
                    $this->ledger->forget(($this->account)(), $key);
                } catch (Throwable) {
                    // The original failure matters more; the entry expires with the window.
                }
            }

            throw $e;
        }
    }

    /**
     * Records a guarded query sent earlier, under the key call() claims for it.
     *
     * @param  array<string, string|int|list<string>|null>  $query  the query as the client sends it
     *
     * @throws InvalidRequestException when the attempt is in the future
     * @throws LedgerUnavailableException when the store cannot be read or written
     */
    public function remember(Endpoint $endpoint, #[SensitiveParameter] array $query, DateTimeInterface $sentAt): bool
    {
        return $this->ledger->rememberAt(($this->account)(), self::repetitionKey($endpoint, $query), $sentAt);
    }

    /**
     * The parameters Datadis keys its 24 hour rule on. The official manual lists authorizedNif for
     * consumption but not for maximum power, so for maximum power and reactive data (same
     * parameters) it is left out: two such queries that differ only in authorizedNif collide.
     *
     * @param  array<string, string|int|list<string>|null>  $query
     * @return array<string, string|int|list<string>|null>
     */
    private static function repetitionKey(Endpoint $endpoint, #[SensitiveParameter] array $query): array
    {
        return $endpoint === Endpoint::Consumption ? $query : array_merge($query, ['authorizedNif' => null]);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['ledger' => $this->ledger, 'account' => '[hidden]'];
    }
}
