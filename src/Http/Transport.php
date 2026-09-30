<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Http;

use Lenorix\DatadisClient\Exceptions\TransportException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SensitiveParameter;
use Throwable;

/**
 * Sends a request through the PSR-18 client and reads the whole answer, turning any failure of
 * either into a TransportException.
 *
 * The body is read here because a streaming client only transfers it when it is read, so reading
 * can fail like sending can. The answer comes back with its body in memory.
 *
 * The client's own exception is deliberately NOT chained: its message carries the full URL, and the
 * URL carries the CUPS and the authorizedNif. Only the class name and a redacted excerpt are kept.
 */
final class Transport
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly StreamFactoryInterface $streams,
    ) {}

    /**
     * @param  bool  $preflight  true for calls made before the data request (login): a failure there
     *                           means the data request was never sent.
     */
    public function send(#[SensitiveParameter] RequestInterface $request, string $endpoint, bool $preflight = false): ResponseInterface
    {
        try {
            $response = $this->http->sendRequest($request);
            $body = $response->getBody();

            if ($body->isSeekable()) {
                $body->rewind();
            }

            return $response->withBody($this->streams->createStream($body->getContents()));
        } catch (Throwable $e) {
            // A PSR-18 client should only throw ClientExceptionInterface, but a misbehaving one or a
            // failing body stream must not break the exception contract of this package either.
            throw new TransportException(
                "{$endpoint}: the HTTP client failed before an answer arrived (".$e::class.').',
                detail: $e->getMessage(),
                endpoint: $endpoint,
                requestSent: ! $preflight,
            );
        }
    }
}
