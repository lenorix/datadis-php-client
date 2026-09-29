<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Http;

use Lenorix\DatadisClient\Exceptions\TransportException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Sends a request through the PSR-18 client and turns any client failure into a TransportException.
 *
 * The client's own exception is deliberately NOT chained: its message carries the full URL, and the
 * URL carries the CUPS and the authorizedNif. Only the class name and a redacted excerpt are kept.
 */
final class Transport
{
    public function __construct(private readonly ClientInterface $http) {}

    /**
     * @param  bool  $preflight  true for calls made before the data request (login): a failure there
     *                           means the data request was never sent.
     */
    public function send(RequestInterface $request, string $endpoint, bool $preflight = false): ResponseInterface
    {
        try {
            return $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new TransportException(
                "{$endpoint}: the HTTP client failed before an answer arrived (".$e::class.').',
                detail: $e->getMessage(),
                endpoint: $endpoint,
                requestSent: ! $preflight,
            );
        }
    }
}
