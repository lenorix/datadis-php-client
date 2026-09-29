<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Guard;

use Closure;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use SensitiveParameter;

/**
 * Identifies a query the way Datadis' 24 hour repetition rule does: the account plus the query
 * parameters, whatever the endpoint (one implementation observed max power and reactive colliding
 * when their parameters are equal, so the stricter reading is used).
 *
 * The result is an HMAC so that stored fingerprints cannot be tested against a guessed CUPS. An
 * omitted parameter and an empty one are different queries, and so are different parameter values.
 */
final readonly class RequestFingerprinter
{
    /** The parameters Datadis keys the rule on, in a fixed order. */
    public const array PARAMETERS = ['cups', 'distributorCode', 'startDate', 'endDate', 'measurementType', 'pointType', 'authorizedNif'];

    public const int MIN_KEY_BYTES = 16;

    private Closure $key;

    public function __construct(#[SensitiveParameter] string $key)
    {
        if (strlen($key) < self::MIN_KEY_BYTES) {
            throw new ConfigurationException('The fingerprint key must have at least '.self::MIN_KEY_BYTES.' bytes.');
        }

        $this->key = static fn (): string => $key;
    }

    /** @param array<string, string|int|list<string>|null> $query the query as sent, null for omitted parameters */
    public function fingerprint(string $account, #[SensitiveParameter] array $query): string
    {
        $values = [];
        foreach (self::PARAMETERS as $name) {
            $value = $query[$name] ?? null;
            $values[] = match (true) {
                $value === null => null,
                is_array($value) => array_map(strval(...), $value),
                default => (string) $value,
            };
        }

        // Versioned and domain separated, so the format can change without collisions.
        $payload = json_encode(['datadis-query', 'v1', $account, $values], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return hash_hmac('sha256', $payload, ($this->key)());
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['key' => '[hidden]'];
    }
}
