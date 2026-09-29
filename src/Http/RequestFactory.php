<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Http;

use InvalidArgumentException;
use Lenorix\DatadisClient\ConnectionSettings;
use Lenorix\DatadisClient\DatadisConfig;
use LogicException;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SensitiveParameter;

/**
 * Builds the PSR-7 requests Datadis needs, with the headers it insists on.
 *
 * - `Accept: application/json` is required: without it Datadis answers an empty-body 500 or a 400.
 * - `Accept-Encoding: identity`: some responses are labelled gzip without being gzip.
 * - Credentials travel in the login form body, never in a URL.
 */
final class RequestFactory
{
    public const string LOGIN_PATH = '/nikola-auth/tokens/login';

    private readonly ConnectionSettings $settings;

    private readonly ?DatadisConfig $credentials;

    public function __construct(
        DatadisConfig|ConnectionSettings $settings,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
    ) {
        $this->credentials = $settings instanceof DatadisConfig ? $settings : null;
        $this->settings = $settings instanceof DatadisConfig ? $settings->connection() : $settings;
    }

    public function login(): RequestInterface
    {
        if ($this->credentials === null) {
            throw new LogicException('This request factory has no credentials: build it from a DatadisConfig.');
        }

        $body = http_build_query([
            'username' => $this->credentials->username,
            'password' => $this->credentials->password(),
        ], '', '&', PHP_QUERY_RFC1738);

        return $this->common($this->requests->createRequest('POST', $this->settings->baseUrl.self::LOGIN_PATH))
            ->withHeader('Accept', 'text/plain, */*;q=0.8')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->streams->createStream($body));
    }

    /**
     * @param  array<string, string|int|list<string>|null>  $query  null values and empty lists are dropped;
     *                                                              a list repeats the key once per item
     */
    public function get(string $path, #[SensitiveParameter] array $query, #[SensitiveParameter] string $token): RequestInterface
    {
        if (preg_match('/^[A-Za-z0-9._~+\/=-]+$/D', $token) !== 1) {
            throw new InvalidArgumentException('The token contains characters that are not allowed in a header.');
        }

        return $this->publicGet($path, $query)->withHeader('Authorization', 'Bearer '.$token);
    }

    /**
     * An unauthenticated GET, for the public API.
     *
     * @param  array<string, string|int|list<string>|null>  $query
     */
    public function publicGet(string $path, #[SensitiveParameter] array $query): RequestInterface
    {
        $queryString = self::queryString($query);
        $uri = $this->settings->baseUrl.$path.($queryString === '' ? '' : '?'.$queryString);

        return $this->common($this->requests->createRequest('GET', $uri))
            ->withHeader('Accept', 'application/json');
    }

    /** @param  array<string, mixed>  $query */
    private static function queryString(#[SensitiveParameter] array $query): string
    {
        $pairs = [];

        foreach ($query as $name => $value) {
            foreach (self::items($name, $value) as $item) {
                $pairs[] = rawurlencode($name).'='.rawurlencode($item);
            }
        }

        return implode('&', $pairs);
    }

    /** @return list<string> */
    private static function items(string $name, mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_string($value) || is_int($value)) {
            return [(string) $value];
        }

        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("Query parameter {$name} must be a string, an int, a list of strings or null.");
        }

        $items = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new InvalidArgumentException("Every item of query parameter {$name} must be a string.");
            }

            $items[] = $item;
        }

        return $items;
    }

    private function common(RequestInterface $request): RequestInterface
    {
        return $request
            ->withHeader('Accept-Encoding', 'identity')
            ->withHeader('User-Agent', $this->settings->userAgent);
    }
}
