<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Guard;

use Closure;
use Lenorix\DatadisClient\Exceptions\DatadisException;
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
    public function __construct(private RequestLedger $ledger, private string $account) {}

    /**
     * @template T
     *
     * @param  array<string, string|int|list<string>|null>  $query
     * @param  Closure(): T  $send
     * @return T
     */
    public function call(Endpoint $endpoint, string $name, #[SensitiveParameter] array $query, Closure $send): mixed
    {
        if (! $endpoint->isGuarded()) {
            return $send();
        }

        $key = self::repetitionKey($endpoint, $query);

        try {
            $last = $this->ledger->lastAttempt($this->account, $key);
        } catch (LedgerUnavailableException $e) {
            throw new LedgerUnavailableException("{$name}: {$e->getMessage()}", $name, $e);
        }

        if ($last !== null) {
            throw new RepetitionWindowException(
                "{$name}: the same query was already sent at {$last->format(DATE_ATOM)}; Datadis refuses repeating it within 24 hours.",
                endpoint: $name,
                requestSent: false,
            );
        }

        try {
            $this->ledger->record($this->account, $key);
        } catch (LedgerUnavailableException $e) {
            throw new LedgerUnavailableException("{$name}: {$e->getMessage()}", $name, $e);
        }

        try {
            return $send();
        } catch (DatadisException $e) {
            if (! $e->requestSent) {
                try {
                    $this->ledger->forget($this->account, $key);
                } catch (Throwable) {
                    // The original failure matters more; the entry expires with the window.
                }
            }

            throw $e;
        }
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
}
