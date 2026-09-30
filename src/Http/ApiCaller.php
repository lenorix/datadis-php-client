<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Http;

use GuzzleHttp\Psr7\HttpFactory;
use Lenorix\DatadisClient\Auth\TokenProvider;
use Lenorix\DatadisClient\ConnectionSettings;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Exceptions\AuthenticationException;
use Lenorix\DatadisClient\Exceptions\DatadisException;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\SimpleCache\CacheInterface;
use SensitiveParameter;

/**
 * Makes a GET, authenticated when it has a token provider (the public API may go without one).
 *
 * A 401 means the token was rejected: the token is dropped, one new login is made and the call is
 * repeated once. A second 401 is final. A network failure is never retried here, because it may
 * have reached Datadis and Datadis refuses an identical query for 24 hours.
 *
 * @internal
 */
final class ApiCaller
{
    public function __construct(
        private readonly RequestFactory $requests,
        private readonly Transport $transport,
        private readonly ?TokenProvider $tokens,
    ) {}

    /**
     * The wiring the private and the public API share. With a DatadisConfig the calls log in and
     * send the token; with only ConnectionSettings they send none. Guzzle fills in whatever the
     * application does not provide.
     */
    public static function connect(
        DatadisConfig|ConnectionSettings $settings,
        ?ClientInterface $http = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?CacheInterface $tokenCache = null,
        ?ClockInterface $clock = null,
    ): self {
        $factory = new HttpFactory;
        $streamFactory ??= $factory;
        $connection = $settings instanceof DatadisConfig ? $settings->connection() : $settings;
        $requests = new RequestFactory($connection, $requestFactory ?? $factory, $streamFactory);
        $transport = new Transport($http ?? GuzzleClientFactory::create($connection), $streamFactory);
        $tokens = $settings instanceof DatadisConfig ? new TokenProvider($settings, $requests, $transport, $tokenCache, $clock) : null;

        return new self($requests, $transport, $tokens);
    }

    /**
     * @param  array<string, string|int|list<string>|null>  $query
     * @return array<array-key, mixed> the decoded JSON
     */
    public function get(string $path, #[SensitiveParameter] array $query, string $endpoint): array
    {
        return ResponseClassifier::decode($this->send($path, $query, $endpoint), $endpoint);
    }

    /**
     * The body of a successful answer as text, possibly empty. For endpoints without a documented body.
     *
     * @param  array<string, string|int|list<string>|null>  $query
     */
    public function getText(string $path, #[SensitiveParameter] array $query, string $endpoint): string
    {
        return ResponseClassifier::assertSuccessful($this->send($path, $query, $endpoint), $endpoint);
    }

    /** @param  array<string, string|int|list<string>|null>  $query */
    private function send(string $path, #[SensitiveParameter] array $query, string $endpoint): ResponseInterface
    {
        if ($this->tokens === null) {
            return $this->transport->send($this->requests->publicGet($path, $query), $endpoint);
        }

        $response = $this->transport->send($this->requests->get($path, $query, $this->tokens->token()), $endpoint);

        if ($response->getStatusCode() !== 401) {
            return $response;
        }

        $this->tokens->invalidate();

        try {
            $token = $this->tokens->token();
        } catch (DatadisException $e) {
            // The data request already went out once, so whatever happens now it counts as sent.
            throw new AuthenticationException(
                "{$endpoint}: the token was rejected and logging in again failed.",
                401,
                $e->detail,
                $endpoint,
                requestSent: true,
                previous: $e,
            );
        }

        return $this->transport->send($this->requests->get($path, $query, $token), $endpoint);
    }
}
