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
     */
    public function token(): string
    {
        try {
            $cached = ($this->cache)()->get($this->cacheKey);
        } catch (Throwable) {
            $cached = null;
        }

        // The store's TTL is not trusted alone: a store that ignores it would hand back an expired
        // token, and every call would fail with a 401 until it went.
        if (is_string($cached) && self::clean($cached) === $cached && ! $this->expired($cached) && hash('sha256', $cached) !== $this->dropped) {
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
                ($this->cache)()->set($this->cacheKey, $token, $ttl);
            } catch (Throwable) {
                // Not cached: the next call logs in again.
            }
        }

        return $token;
    }

    /** Past its `exp`, less the skew; a token without one is trusted to the TTL it was stored with. */
    private function expired(#[SensitiveParameter] string $token): bool
    {
        $expiry = JwtExpiry::read($token);

        return $expiry !== null && $expiry - self::SKEW_SECONDS <= $this->clock->now()->getTimestamp();
    }

    /**
     * Drops the cached token, so the next token() logs in. The token is also remembered as
     * dropped: a store that fails to delete it would otherwise hand it back.
     */
    public function invalidate(): void
    {
        try {
            $cached = ($this->cache)()->get($this->cacheKey);
        } catch (Throwable) {
            $cached = null;
        }

        if (is_string($cached)) {
            $this->dropped = hash('sha256', $cached);
        }

        try {
            ($this->cache)()->delete($this->cacheKey);
        } catch (Throwable) {
            // Nothing else to do: the token is remembered as dropped, and is not used again.
        }
    }

    /**
     * The text with the password and the username removed: as they are, form encoded (`+` for a
     * space), percent encoded (`%20`), escaped in JSON (with or without escaped slashes and
     * non-ASCII characters) and escaped in HTML. Case is ignored, since
     * percent encoding may use either case; removing too much is the safe side.
     */
    private function withoutCredentials(#[SensitiveParameter] string $text): string
    {
        $forms = [];

        foreach ([$this->config->password(), $this->config->username()] as $secret) {
            $forms = [...$forms, $secret, urlencode($secret), rawurlencode($secret), htmlspecialchars($secret, ENT_QUOTES | ENT_HTML5)];

            // JSON, with and without escaped slashes and non-ASCII characters.
            foreach ([0, JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE] as $flags) {
                $json = json_encode($secret, $flags);
                $forms[] = $json === false ? $secret : substr($json, 1, -1);
            }
        }

        // Longest first, so a form that contains another is removed whole.
        $forms = array_values(array_unique(array_filter($forms, static fn (string $form): bool => $form !== '')));
        usort($forms, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return str_ireplace($forms, PersonalDataRedactor::PLACEHOLDER, $text);
    }

    private function login(): string
    {
        $response = $this->transport->send($this->requests->login($this->config), self::ENDPOINT, preflight: true);
        $status = $response->getStatusCode();
        $text = ResponseClassifier::text($response);
        // The login body is where credentials were submitted, so an error body that echoes them must not
        // reach a message. The password cannot be recognised by shape, hence the exact match, in the
        // forms a server echoes a form field in.
        $detail = PersonalDataRedactor::excerpt($this->withoutCredentials($text));
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

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['config' => $this->config, 'cache' => '[hidden]'];
    }
}
