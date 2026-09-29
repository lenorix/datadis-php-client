<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient;

use Lenorix\DatadisClient\Exceptions\ConfigurationException;

/**
 * Where and how to connect, without credentials. The public API needs only this; the private API
 * gets it from DatadisConfig. Invalid settings raise a ConfigurationException before anything is sent.
 */
final readonly class ConnectionSettings
{
    /** Without a trailing slash. Always HTTPS. */
    public string $baseUrl;

    /**
     * @param  float  $timeout  seconds for a whole call. Datadis is slow (contract detail about 15 s, consumption tens of seconds).
     */
    public function __construct(
        string $baseUrl = DatadisConfig::DEFAULT_BASE_URL,
        public float $timeout = 120.0,
        public float $connectTimeout = 10.0,
        public string $userAgent = DatadisConfig::DEFAULT_USER_AGENT,
    ) {
        if ($timeout <= 0 || $connectTimeout <= 0) {
            throw new ConfigurationException('Timeouts must be greater than zero.');
        }

        if ($userAgent === '' || preg_match('/[\x00-\x1f\x7f]/', $userAgent) === 1) {
            throw new ConfigurationException('The user agent must be a non-empty single line.');
        }

        $this->baseUrl = self::normaliseBaseUrl($baseUrl);
    }

    private static function normaliseBaseUrl(string $baseUrl): string
    {
        $parts = parse_url(trim($baseUrl));

        if ($parts === false
            || ($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') === ''
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new ConfigurationException('The base URL must be an https URL without credentials, query or fragment.');
        }

        return rtrim(trim($baseUrl), '/');
    }
}
