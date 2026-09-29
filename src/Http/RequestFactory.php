<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Http;

use InvalidArgumentException;
use Lenorix\DatadisClient\DatadisConfig;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

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

    public function __construct(
        private readonly DatadisConfig $config,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
    ) {}

    public function login(): RequestInterface
    {
        $body = http_build_query([
            'username' => $this->config->username,
            'password' => $this->config->password(),
        ], '', '&', PHP_QUERY_RFC1738);

        return $this->common($this->requests->createRequest('POST', $this->config->baseUrl.self::LOGIN_PATH))
            ->withHeader('Accept', 'text/plain, */*;q=0.8')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->streams->createStream($body));
    }

    /**
     * @param  array<string, string|int|null>  $query  null values are dropped
     */
    public function get(string $path, array $query, string $token): RequestInterface
    {
        if (preg_match('/^[A-Za-z0-9._~+\/=-]+$/D', $token) !== 1) {
            throw new InvalidArgumentException('The token contains characters that are not allowed in a header.');
        }

        $params = [];
        foreach ($query as $name => $value) {
            if ($value === null) {
                continue;
            }

            if (! is_string($value) && ! is_int($value)) {
                throw new InvalidArgumentException("Query parameter {$name} must be a string, an int or null.");
            }

            $params[$name] = $value;
        }

        $queryString = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $uri = $this->config->baseUrl.$path.($queryString === '' ? '' : '?'.$queryString);

        return $this->common($this->requests->createRequest('GET', $uri))
            ->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Bearer '.$token);
    }

    private function common(RequestInterface $request): RequestInterface
    {
        return $request
            ->withHeader('Accept-Encoding', 'identity')
            ->withHeader('User-Agent', $this->config->userAgent);
    }
}
