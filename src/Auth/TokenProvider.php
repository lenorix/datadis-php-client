<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Auth;

use Closure;
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
use SensitiveParameter;
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

    /**
     * The store holds the live token, and one given by the application may show its values when
     * dumped. var_export cannot show what a closure holds, and __debugInfo leaves it out of
     * var_dump and print_r.
     *
     * @var Closure(): CacheInterface
     */
    private readonly Closure $cache;

    private readonly ClockInterface $clock;

    private readonly string $cacheKey;

    /**
     * A hash of the token this provider dropped last: a store whose delete() fails keeps handing
     * it back, and a token Datadis rejected (or a check asked to ignore) must not be used again.
     */
    private ?string $dropped = null;

    public function __construct(
        private readonly DatadisConfig $config,
        private readonly RequestFactory $requests,
        private readonly Transport $transport,
        ?CacheInterface $cache = null,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock;
        $store = $cache ?? new InMemoryCache($this->clock);
        $this->cache = static fn (): CacheInterface => $store;
        // PSR-16 keys allow only [A-Za-z0-9_.] and 64 characters.
        $this->cacheKey = 'datadis_token_'.substr(hash('sha256', $config->baseUrl."\n".$config->username()), 0, 40);
    }

    /**
     * The cache is only an optimisation: a store that fails or holds something that is not a
     * usable token never breaks a call, it just means logging in again.
     *
     * @param  bool  $fresh  log in now, whatever the cache holds; the new token replaces the cached one
     */
    public function token(bool $fresh = false): string
    {
        try {
            $cached = $fresh ? null : ($this->cache)()->get($this->cacheKey);
        } catch (Throwable) {
            $cached = null;
        }

        // The store's TTL is not trusted alone: a store that ignores it would hand back an expired
        // token, and every call would fail with a 401 until it went.
        [$usable, $expiry] = is_string($cached) ? self::unpack($cached) : [null, null];

        if ($usable !== null && $expiry !== null && $expiry - self::SKEW_SECONDS > $this->clock->now()->getTimestamp() && hash('sha256', $usable) !== $this->dropped) {
            return $usable;
        }

        if ($cached !== null) {
            $this->invalidate();
        }

        $token = $this->login();

        // Datadis may hand back the very token it was asked to replace: a login that succeeds with
        // it shows it is good, so it is no longer treated as dropped, or every call would log in.
        if ($this->dropped === hash('sha256', $token)) {
            $this->dropped = null;
        }

        $claimed = JwtExpiry::read($token);
        $expiry = $claimed ?? $this->clock->now()->getTimestamp() + self::FALLBACK_TTL_SECONDS;
        $ttl = $expiry - $this->clock->now()->getTimestamp() - self::SKEW_SECONDS;

        if ($ttl > 0) {
            try {
                // A token without `exp` is stored with the expiry assumed for it, so that a store
                // ignoring the TTL cannot keep it past that time.
                ($this->cache)()->set($this->cacheKey, $claimed === null ? $expiry.':'.$token : $token, $ttl);
            } catch (Throwable) {
                // Not cached: the next call logs in again.
            }
        }

        return $token;
    }

    /**
     * What the store holds: a token with its `exp`, or `expiry:token` for one without. Anything
     * else (a token without `exp` stored bare, junk) has no expiry known, and is not used.
     *
     * @return array{?string, ?int} the token and its expiry
     */
    private static function unpack(#[SensitiveParameter] string $cached): array
    {
        if (preg_match('/^(\d{1,12}):(.+)$/sD', $cached, $parts) === 1) {
            $token = self::clean($parts[2]) === $parts[2] ? $parts[2] : null;

            return [$token, $token === null || JwtExpiry::read($token) !== null ? null : (int) $parts[1]];
        }

        $token = self::clean($cached) === $cached ? $cached : null;

        return [$token, $token === null ? null : JwtExpiry::read($token)];
    }

    /**
     * Drops a token, so it is not used again: the one Datadis rejected, or the cached one. It is
     * remembered as dropped, since a store that fails to delete it would hand it back. The cache
     * is cleared only while it still holds that token: another client sharing it may already have
     * logged in and stored a new one, which stays for every client to use.
     */
    public function invalidate(#[SensitiveParameter] ?string $rejected = null): void
    {
        try {
            $cached = ($this->cache)()->get($this->cacheKey);
        } catch (Throwable) {
            $cached = null;
        }

        $held = is_string($cached) ? self::unpack($cached)[0] ?? $cached : null;
        $rejected ??= $held;

        if ($rejected !== null) {
            $this->dropped = hash('sha256', $rejected);
        }

        if ($cached === null || ($held !== null && $rejected !== null && ! hash_equals($rejected, $held))) {
            return;
        }

        try {
            ($this->cache)()->delete($this->cacheKey);
        } catch (Throwable) {
            // Nothing else to do: the token is remembered as dropped, and is not used again.
        }
    }

    private function login(): string
    {
        $response = $this->transport->send($this->requests->login($this->config), self::ENDPOINT, preflight: true);
        $status = $response->getStatusCode();
        $text = ResponseClassifier::text($response);
        // The login body is where credentials were submitted, so an error body that echoes them must not
        // reach a message. The password cannot be recognised by shape, hence the exact match, in the
        // forms a server echoes a form field in.
        $detail = ResponseClassifier::errorText(PersonalDataRedactor::withoutSecrets($text, [$this->config->password(), $this->config->username()]));
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

    /**
     * A JWT, as Datadis issues (verified): three base64url parts separated by dots, the last one
     * possibly empty. A login that answers 200 with anything else (`null`, `OK`, an HTML page) has
     * not handed over a token, and sending it would only cost a 401.
     */
    private const string JWT_PATTERN = '/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]*$/D';

    /** The bare token, or null when the body is not a JWT. */
    private static function clean(#[SensitiveParameter] string $text): ?string
    {
        $token = trim($text);
        $token = trim($token, "\"'");
        $token = trim($token);
        $token = preg_replace('/^Bearer\s+/i', '', $token) ?? $token;

        return preg_match(self::JWT_PATTERN, $token) === 1 ? $token : null;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['config' => $this->config, 'cache' => '[hidden]'];
    }
}
