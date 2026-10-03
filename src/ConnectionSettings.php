<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient;

use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use SensitiveParameter;

/**
 * Where and how to connect, without the credentials, which DatadisConfig adds. Both APIs need the
 * account. Invalid settings raise a ConfigurationException before anything is sent.
 */
final readonly class ConnectionSettings
{
    /** Without a trailing slash. Always HTTPS. */
    public string $baseUrl;

    /**
     * @param  float  $timeout  seconds for a whole call. Datadis is slow (contract detail about 15 s, consumption tens of seconds).
     */
    public function __construct(
        // A misplaced setting may hold credentials or a NIF: kept out of traces like the others.
        #[SensitiveParameter] string $baseUrl = DatadisConfig::DEFAULT_BASE_URL,
        public float $timeout = DatadisConfig::DEFAULT_TIMEOUT,
        public float $connectTimeout = DatadisConfig::DEFAULT_CONNECT_TIMEOUT,
        #[SensitiveParameter] public string $userAgent = DatadisConfig::DEFAULT_USER_AGENT,
    ) {
        // Guzzle works in milliseconds: anything shorter becomes 0, which means "wait forever".
        if (! is_finite($timeout) || ! is_finite($connectTimeout) || $timeout < 0.001 || $connectTimeout < 0.001) {
            throw new ConfigurationException('Timeouts must be finite and at least one millisecond.');
        }

        if ($userAgent === '' || preg_match('/[\x00-\x1f\x7f]/', $userAgent) === 1) {
            throw new ConfigurationException('The user agent must be a non-empty single line.');
        }

        $this->baseUrl = self::normaliseBaseUrl($baseUrl);
    }

    private static function normaliseBaseUrl(#[SensitiveParameter] string $baseUrl): string
    {
        $parts = parse_url(trim($baseUrl));

        if ($parts === false
            || ($parts['scheme'] ?? '') !== 'https'
            || ! self::isHost($parts['host'] ?? '')
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new ConfigurationException('The base URL must be an https URL without credentials, query or fragment.');
        }

        return rtrim(trim($baseUrl), '/');
    }

    /** A host name or a bracketed IPv6 address; anything else would only fail when a request is built. */
    private static function isHost(string $host): bool
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }

        return $host !== '' && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }
}
