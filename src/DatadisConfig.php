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

    public float $timeout;

    public float $connectTimeout;

    public string $userAgent;

    private ConnectionSettings $connection;

    private Closure $password;

    /**
     * @param  float  $timeout  seconds for a whole call. Datadis is slow (contract detail about 15 s, consumption tens of seconds).
     */
    public function __construct(
        string $username,
        #[SensitiveParameter] string $password,
        string $baseUrl = self::DEFAULT_BASE_URL,
        float $timeout = 120.0,
        float $connectTimeout = 10.0,
        string $userAgent = self::DEFAULT_USER_AGENT,
    ) {
        $username = strtoupper(trim($username));

        if ($username === '') {
            throw new ConfigurationException('The Datadis username is empty.');
        }

        if ($password === '') {
            throw new ConfigurationException('The Datadis password is empty.');
        }

        $this->connection = new ConnectionSettings($baseUrl, $timeout, $connectTimeout, $userAgent);
        $this->username = $username;
        $this->baseUrl = $this->connection->baseUrl;
        $this->timeout = $this->connection->timeout;
        $this->connectTimeout = $this->connection->connectTimeout;
        $this->userAgent = $this->connection->userAgent;
        $this->password = static fn (): string => $password;
    }

    public function connection(): ConnectionSettings
    {
        return $this->connection;
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
}
