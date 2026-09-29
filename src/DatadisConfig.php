<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient;

use Closure;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use LogicException;
use SensitiveParameter;

/**
 * Immutable connection settings.
 *
 * Invalid settings raise a ConfigurationException, which means nothing was sent. The password lives
 * in a closure so that var_dump(), print_r(), var_export() and serialize() cannot expose it.
 */
final readonly class DatadisConfig
{
    public const string VERSION = '0.1';

    public const string DEFAULT_BASE_URL = 'https://datadis.es';

    /** Some hosts refuse unknown or library default agents, so the agent identifies this package but looks ordinary. */
    public const string DEFAULT_USER_AGENT = 'Mozilla/5.0 (compatible; lenorix-datadis-client/'.self::VERSION.'; +https://github.com/lenorix/datadis-client)';

    /** The account's NIF, NIE or CIF, trimmed and uppercase. */
    public string $username;

    /** Without a trailing slash. Always HTTPS. */
    public string $baseUrl;

    private Closure $password;

    /**
     * @param  float  $timeout  seconds for a whole call. Datadis is slow (contract detail about 15 s, consumption tens of seconds).
     */
    public function __construct(
        string $username,
        #[SensitiveParameter] string $password,
        string $baseUrl = self::DEFAULT_BASE_URL,
        public float $timeout = 120.0,
        public float $connectTimeout = 10.0,
        public string $userAgent = self::DEFAULT_USER_AGENT,
    ) {
        $username = strtoupper(trim($username));

        if ($username === '') {
            throw new ConfigurationException('The Datadis username is empty.');
        }

        if ($password === '') {
            throw new ConfigurationException('The Datadis password is empty.');
        }

        if ($timeout <= 0 || $connectTimeout <= 0) {
            throw new ConfigurationException('Timeouts must be greater than zero.');
        }

        if ($userAgent === '' || preg_match('/[\x00-\x1f\x7f]/', $userAgent) === 1) {
            throw new ConfigurationException('The user agent must be a non-empty single line.');
        }

        $this->username = $username;
        $this->baseUrl = self::normaliseBaseUrl($baseUrl);
        $this->password = static fn (): string => $password;
    }

    /** Only the request factory should call this. */
    public function password(): string
    {
        return ($this->password)();
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'username' => $this->username,
            'baseUrl' => $this->baseUrl,
            'password' => '[hidden]',
            'timeout' => $this->timeout,
            'connectTimeout' => $this->connectTimeout,
            'userAgent' => $this->userAgent,
        ];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('DatadisConfig holds a password and must not be serialised.');
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
