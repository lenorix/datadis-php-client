<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Http;

use Closure;
use InvalidArgumentException;
use Lenorix\DatadisClient\ConnectionSettings;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SensitiveParameter;
use Throwable;

/**
 * Builds the PSR-7 requests Datadis needs, with the headers it insists on.
 *
 * - `Accept: application/json` is required: without it Datadis answers an empty-body 500 or a 400.
 * - `Accept-Encoding: identity`: some responses are labelled gzip without being gzip.
 * - Credentials travel in the login form body, never in a URL.
 *
 * @internal
 */
final class RequestFactory
{
    public const string LOGIN_PATH = '/nikola-auth/tokens/login';

    /** What a token may contain to travel in a header: a JWT, or any other base64 or URL-safe text. */
    public const string TOKEN_PATTERN = '/^[A-Za-z0-9._~+\/=-]+$/D';

    public function __construct(
        private readonly ConnectionSettings $settings,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
    ) {}

    public function login(#[SensitiveParameter] DatadisConfig $credentials): RequestInterface
    {
        $body = http_build_query([
            'username' => $credentials->username,
            'password' => $credentials->password(),
        ], '', '&', PHP_QUERY_RFC1738);

        return $this->build(fn () => $this->common($this->requests->createRequest('POST', $this->settings->baseUrl.self::LOGIN_PATH))
            ->withHeader('Accept', 'text/plain, */*;q=0.8')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->streams->createStream($body)));
    }

    /**
     * @param  array<string, string|int|list<string>|null>  $query  null values and empty lists are dropped;
     *                                                              a list repeats the key once per item
     */
    public function get(string $path, #[SensitiveParameter] array $query, #[SensitiveParameter] string $token): RequestInterface
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new InvalidArgumentException('The token contains characters that are not allowed in a header.');
        }

        return $this->plainGet($path, $query)->withHeader('Authorization', 'Bearer '.$token);
    }

    /** @param  array<string, string|int|list<string>|null>  $query */
    private function plainGet(string $path, #[SensitiveParameter] array $query): RequestInterface
    {
        $queryString = self::queryString($query);
        $uri = $this->settings->baseUrl.$path.($queryString === '' ? '' : '?'.$queryString);

        return $this->build(fn () => $this->common($this->requests->createRequest('GET', $uri))
            ->withHeader('Accept', 'application/json'));
    }

    /**
     * A request the PSR-17 factory cannot build never leaves, so its failure is a setup problem
     * with `requestSent = false`. The original is not chained: its message may carry the URL.
     *
     * @param  Closure(): RequestInterface  $build
     */
    private function build(Closure $build): RequestInterface
    {
        try {
            return $build();
        } catch (Throwable $e) {
            throw new ConfigurationException('The request could not be built ('.$e::class.').');
        }
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
