<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Auth;

use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Exceptions\AuthenticationException;
use Lenorix\DatadisClient\Exceptions\RequestRejectedException;
use Lenorix\DatadisClient\Exceptions\ServiceUnavailableException;
use Lenorix\DatadisClient\Exceptions\UninterpretableResponseException;
use Lenorix\DatadisClient\Http\RequestFactory;
use Lenorix\DatadisClient\Http\ResponseClassifier;
use Lenorix\DatadisClient\Http\Transport;
use Lenorix\DatadisClient\Support\InMemoryCache;
use Lenorix\DatadisClient\Support\PersonalDataRedactor;
use Lenorix\DatadisClient\Support\SystemClock;
use Psr\Clock\ClockInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Logs in and keeps the token until shortly before it expires.
 *
 * A token lasts 24 hours (verified), but the JWT `exp` claim decides, and a conservative lifetime
 * is used when the token carries none. The cache can be shared between processes (any PSR-16
 * store): it holds a live credential, so treat it like one.
 *
 * Every failure here is raised with `requestSent = false` because it happens before the data request.
 *
 * @internal
 */
final class TokenProvider
{
    /** A token is dropped this many seconds before it expires. */
    public const int SKEW_SECONDS = 120;

    /** Assumed lifetime for a token without an `exp` claim. An assumption, not a measurement. */
    public const int FALLBACK_TTL_SECONDS = 3600;

    private const string ENDPOINT = 'login';

    private readonly CacheInterface $cache;

    private readonly ClockInterface $clock;

    private readonly string $cacheKey;

    public function __construct(
        private readonly DatadisConfig $config,
        private readonly RequestFactory $requests,
        private readonly Transport $transport,
        ?CacheInterface $cache = null,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock;
        $this->cache = $cache ?? new InMemoryCache($this->clock);
        // PSR-16 keys allow only [A-Za-z0-9_.] and 64 characters.
        $this->cacheKey = 'datadis_token_'.substr(hash('sha256', $config->baseUrl."\n".$config->username), 0, 40);
    }

    /**
     * The cache is only an optimisation: a store that fails or holds something that is not a
     * usable token never breaks a call, it just means logging in again.
     */
    public function token(): string
    {
        try {
            $cached = $this->cache->get($this->cacheKey);
        } catch (Throwable) {
            $cached = null;
        }

        if (is_string($cached) && self::clean($cached) === $cached) {
            return $cached;
        }

        if ($cached !== null) {
            $this->invalidate();
        }

        $token = $this->login();

        $expiry = JwtExpiry::read($token) ?? $this->clock->now()->getTimestamp() + self::FALLBACK_TTL_SECONDS;
        $ttl = $expiry - $this->clock->now()->getTimestamp() - self::SKEW_SECONDS;

        if ($ttl > 0) {
            try {
                $this->cache->set($this->cacheKey, $token, $ttl);
            } catch (Throwable) {
                // Not cached: the next call logs in again.
            }
        }

        return $token;
    }

    public function invalidate(): void
    {
        try {
            $this->cache->delete($this->cacheKey);
        } catch (Throwable) {
            // Nothing else to do: a stale token is detected by the 401 it causes.
        }
    }

    private function login(): string
    {
        $response = $this->transport->send($this->requests->login($this->config), self::ENDPOINT, preflight: true);
        $status = $response->getStatusCode();
        $text = ResponseClassifier::text($response);
        // The login body is where credentials were submitted, so an error body that echoes them must not
        // reach a message. The password cannot be recognised by shape, hence the exact match.
        $detail = PersonalDataRedactor::excerpt(
            str_replace([$this->config->password(), $this->config->username], PersonalDataRedactor::PLACEHOLDER, $text),
        );
        $message = self::ENDPOINT.": Datadis answered HTTP {$status}".($detail === '' ? '.' : " · {$detail}");

        if ($status === 401 || $status === 403) {
            throw new AuthenticationException($message, $status, $detail, self::ENDPOINT, requestSent: false);
        }

        if ($status >= 500) {
            throw new ServiceUnavailableException($message, $status, $detail, self::ENDPOINT, requestSent: false);
        }

        if ($status < 200 || $status >= 300) {
            throw new RequestRejectedException($message, $status, $detail, self::ENDPOINT, requestSent: false);
        }

        $token = self::clean($text);

        if ($token === null) {
            throw new UninterpretableResponseException(
                self::ENDPOINT.': the login answer is not a token.',
                $status,
                '',
                self::ENDPOINT,
                requestSent: false,
            );
        }

        return $token;
    }

    /** The bare token, or null when the body is not one (an HTML page, JSON, text with spaces). */
    private static function clean(string $text): ?string
    {
        $token = trim($text);
        $token = trim($token, "\"'");
        $token = trim($token);
        $token = preg_replace('/^Bearer\s+/i', '', $token) ?? $token;

        return preg_match(RequestFactory::TOKEN_PATTERN, $token) === 1 ? $token : null;
    }
}
