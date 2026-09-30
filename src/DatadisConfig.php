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
    public const string DEFAULT_BASE_URL = 'https://datadis.es';

    /**
     * Some hosts refuse unknown or library default agents, so the agent identifies this package but
     * looks ordinary. It carries no version number, which would go stale with every release.
     */
    public const string DEFAULT_USER_AGENT = 'Mozilla/5.0 (compatible; lenorix-datadis-client; +https://github.com/lenorix/datadis-client)';

    /** The account's NIF, NIE or CIF, trimmed and uppercase. */
    public string $username;

    /** Without a trailing slash. Always HTTPS. */
    public string $baseUrl;

    public float $timeout;

    public float $connectTimeout;

    public string $userAgent;

    private ConnectionSettings $connection;

    /** @var Closure(): string */
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

    /**
     * Builds the configuration from a plain array, as an application keeps it in a configuration
     * file or reads it from the environment: `username`, `password`, and optionally `base_url`,
     * `timeout`, `connect_timeout` (seconds, numbers or numeric text) and `user_agent`; names with
     * dashes (`base-url`) work too. Empty values count as not given; unknown keys are ignored so the
     * array can hold other settings too.
     *
     * @param  array<array-key, mixed>  $settings
     *
     * @throws ConfigurationException naming the setting that is missing or wrong
     */
    public static function fromArray(#[SensitiveParameter] array $settings): self
    {
        return new self(
            self::setting($settings, 'username') ?? throw new ConfigurationException('The Datadis setting "username" is missing.'),
            self::passwordFrom($settings),
            self::setting($settings, 'base_url') ?? self::DEFAULT_BASE_URL,
            self::seconds($settings, 'timeout', 120.0),
            self::seconds($settings, 'connect_timeout', 10.0),
            self::setting($settings, 'user_agent') ?? self::DEFAULT_USER_AGENT,
        );
    }

    /**
     * A text setting, trimmed; null when absent or empty.
     *
     * @param  array<array-key, mixed>  $settings
     *
     * @internal
     */
    public static function setting(#[SensitiveParameter] array $settings, string $key): ?string
    {
        $value = self::raw($settings, $key);

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw new ConfigurationException("The Datadis setting \"{$key}\" must be text.");
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * The value of a setting named in snake case (`base_url`) or with dashes (`base-url`).
     *
     * @param  array<array-key, mixed>  $settings
     */
    private static function raw(#[SensitiveParameter] array $settings, string $key): mixed
    {
        return $settings[$key] ?? $settings[str_replace('_', '-', $key)] ?? null;
    }

    /**
     * The password exactly as given: spaces can be part of it.
     *
     * @param  array<array-key, mixed>  $settings
     */
    private static function passwordFrom(#[SensitiveParameter] array $settings): string
    {
        $password = $settings['password'] ?? null;

        if (! is_string($password) || $password === '') {
            throw new ConfigurationException('The Datadis setting "password" is missing.');
        }

        return $password;
    }

    /** @param  array<array-key, mixed>  $settings */
    private static function seconds(#[SensitiveParameter] array $settings, string $key, float $default): float
    {
        $value = self::raw($settings, $key);

        if ($value === null || $value === '') {
            return $default;
        }

        if (! is_numeric($value)) {
            throw new ConfigurationException("The Datadis setting \"{$key}\" must be a number of seconds.");
        }

        return (float) $value;
    }

    /** @internal */
    public function connection(): ConnectionSettings
    {
        return $this->connection;
    }

    /**
     * Only the login should call this.
     *
     * @internal
     */
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
