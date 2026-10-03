<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient;

use Closure;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Lenorix\DatadisClient\Values\Nif;
use LogicException;
use SensitiveParameter;

/**
 * The account (username and password) and the connection settings of the private API, immutable.
 *
 * Invalid settings raise a ConfigurationException, which means nothing was sent. The password and the
 * account's NIF live in closures, which var_export() cannot show, __debugInfo leaves them out of
 * var_dump() and print_r(), and serialize() is refused.
 */
final readonly class DatadisConfig
{
    public const string DEFAULT_BASE_URL = 'https://datadis.es';

    /** Seconds for a whole call: Datadis is slow (contract detail about 15 s, consumption tens of seconds). */
    public const float DEFAULT_TIMEOUT = 120.0;

    public const float DEFAULT_CONNECT_TIMEOUT = 10.0;

    /**
     * Some hosts refuse unknown or library default agents, so the agent identifies this package but
     * looks ordinary. It carries no version number, which would go stale with every release.
     */
    public const string DEFAULT_USER_AGENT = 'Mozilla/5.0 (compatible; lenorix-datadis-client; +https://github.com/lenorix/datadis-php-client)';

    /** @var Closure(): string */
    private Closure $username;

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
     * @param  bool  $checkUsernameControl  false takes a username with the shape of a NIF, NIE or CIF whose
     *                                      control character does not match, as Nif::fromString() does
     */
    public function __construct(
        #[SensitiveParameter] string $username,
        #[SensitiveParameter] string $password,
        #[SensitiveParameter] string $baseUrl = self::DEFAULT_BASE_URL,
        float $timeout = self::DEFAULT_TIMEOUT,
        float $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
        #[SensitiveParameter] string $userAgent = self::DEFAULT_USER_AGENT,
        bool $checkUsernameControl = true,
    ) {
        $username = strtoupper(trim($username));

        if ($username === '') {
            throw new ConfigurationException('The Datadis username is empty.');
        }

        // Datadis accounts are named by their NIF, NIE or CIF: anything else could only fail at the login.
        if (! Nif::isValid($username, $checkUsernameControl)) {
            throw new ConfigurationException($checkUsernameControl && Nif::isValid($username, false)
                ? 'The control character of the Datadis username (a NIF, NIE or CIF) does not match; check it, or pass checkUsernameControl: false.'
                : 'The Datadis username must be the account\'s NIF, NIE or CIF.');
        }

        if ($password === '') {
            throw new ConfigurationException('The Datadis password is empty.');
        }

        $this->connection = new ConnectionSettings($baseUrl, $timeout, $connectTimeout, $userAgent);
        $this->username = static fn (): string => $username;
        $this->baseUrl = $this->connection->baseUrl;
        $this->timeout = $this->connection->timeout;
        $this->connectTimeout = $this->connection->connectTimeout;
        $this->userAgent = $this->connection->userAgent;
        $this->password = static fn (): string => $password;
    }

    /**
     * Builds the configuration from a plain array, as an application keeps it in a configuration
     * file or reads it from the environment: `username`, `password`, and optionally `base_url`,
     * `timeout`, `connect_timeout` (seconds, numbers or numeric text), `user_agent` and
     * `check_username_control` (a boolean, or `true`/`false`/`1`/`0` as text); names with
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
            self::seconds($settings, 'timeout', self::DEFAULT_TIMEOUT),
            self::seconds($settings, 'connect_timeout', self::DEFAULT_CONNECT_TIMEOUT),
            self::setting($settings, 'user_agent') ?? self::DEFAULT_USER_AGENT,
            self::flag($settings, 'check_username_control', true),
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

        if ($password === null || $password === '') {
            throw new ConfigurationException('The Datadis setting "password" is missing.');
        }

        // A number is not taken as text: it may have lost a leading zero or a digit on the way.
        if (! is_string($password)) {
            throw new ConfigurationException('The Datadis setting "password" must be text; quote it in the configuration.');
        }

        return $password;
    }

    /** @param  array<array-key, mixed>  $settings */
    private static function seconds(#[SensitiveParameter] array $settings, string $key, float $default): float
    {
        $value = self::raw($settings, $key);
        // Empty values, spaces only included, count as not given.
        $value = is_string($value) ? trim($value) : $value;

        if ($value === null || $value === '') {
            return $default;
        }

        if (! is_numeric($value)) {
            throw new ConfigurationException("The Datadis setting \"{$key}\" must be a number of seconds.");
        }

        return (float) $value;
    }

    /** @param  array<array-key, mixed>  $settings */
    private static function flag(#[SensitiveParameter] array $settings, string $key, bool $default): bool
    {
        $value = self::raw($settings, $key);
        // Empty values, spaces only included, count as not given: filter_var() reads " " as false.
        $value = is_string($value) ? trim($value) : $value;

        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            ?? throw new ConfigurationException("The Datadis setting \"{$key}\" must be true or false.");
    }

    /** The account's NIF, NIE or CIF, trimmed and uppercase. */
    public function username(): string
    {
        return ($this->username)();
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
            'username' => '[hidden]',
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
