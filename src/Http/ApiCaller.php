<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Http;

use DateTimeImmutable;
use GuzzleHttp\Psr7\HttpFactory;
use Lenorix\DatadisClient\Auth\JwtExpiry;
use Lenorix\DatadisClient\Auth\TokenProvider;
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
 * Makes an authenticated GET.
 *
 * A 401 means the token was rejected: the token is dropped, one new login is made and the call is
 * repeated once. A second 401 is final. Only a call that is safe to repeat is sent again: whether
 * Datadis acted on the rejected one is unknown, so a guarded query could cost the query for 24
 * hours, and a call that changes data (an authorization, unlinking a user) could be applied twice. A network failure is never retried here, because it may
 * have reached Datadis and Datadis refuses an identical query for 24 hours.
 *
 * @internal
 */
final class ApiCaller
{
    public function __construct(
        private readonly RequestFactory $requests,
        private readonly Transport $transport,
        private readonly TokenProvider $tokens,
    ) {}

    /**
     * The wiring the private and the public API share: both log in with the account and send its
     * token. Guzzle fills in whatever the application does not provide.
     */
    public static function connect(
        DatadisConfig $config,
        ?ClientInterface $http = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?CacheInterface $tokenCache = null,
        ?ClockInterface $clock = null,
    ): self {
        $factory = new HttpFactory;
        $streamFactory ??= $factory;
        $requests = new RequestFactory($config->connection(), $requestFactory ?? $factory, $streamFactory);
        $transport = new Transport($http ?? GuzzleClientFactory::create($config), $streamFactory);

        return new self($requests, $transport, new TokenProvider($config, $requests, $transport, $tokenCache, $clock));
    }

    /**
     * Logs in, or takes the cached token, and tells when that token expires: null when it says
     * nothing about it. `fresh` drops the cached token first, so the credentials are tried now.
     */
    public function tokenExpiry(bool $fresh = false): ?DateTimeImmutable
    {
        if ($fresh) {
            $this->tokens->invalidate();
        }

        $expiry = JwtExpiry::read($this->tokens->token());

        return $expiry === null ? null : (new DateTimeImmutable)->setTimestamp($expiry);
    }

    /**
     * @param  array<string, string|int|list<string>|null>  $query
     * @return array<array-key, mixed> the decoded JSON
     */
    public function get(string $path, #[SensitiveParameter] array $query, string $endpoint, bool $sendAgainAfter401 = true): array
    {
        return ResponseClassifier::decode($this->send($path, $query, $endpoint, $sendAgainAfter401), $endpoint);
    }

    /**
     * The body of a successful answer as text, possibly empty. For endpoints without a documented body.
     *
     * @param  array<string, string|int|list<string>|null>  $query
     */
    public function getText(string $path, #[SensitiveParameter] array $query, string $endpoint, bool $sendAgainAfter401 = true): string
    {
        return ResponseClassifier::assertSuccessful($this->send($path, $query, $endpoint, $sendAgainAfter401), $endpoint);
    }

    /** @param  array<string, string|int|list<string>|null>  $query */
    private function send(string $path, #[SensitiveParameter] array $query, string $endpoint, bool $sendAgainAfter401 = true): ResponseInterface
    {
        $response = $this->transport->send($this->requests->get($path, $query, $this->tokens->token()), $endpoint);

        if ($response->getStatusCode() !== 401) {
            return $response;
        }

        $this->tokens->invalidate();

        if (! $sendAgainAfter401) {
            // The 401 itself as an AuthenticationException, sent; the next call logs in again.
            ResponseClassifier::assertSuccessful($response, $endpoint);
        }

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
