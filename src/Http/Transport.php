<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Http;

use Closure;
use Lenorix\DatadisClient\Exceptions\TransportException;
use Lenorix\DatadisClient\Support\PersonalDataRedactor;
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
 *
 * @internal
 */
final class Transport
{
    /**
     * The HTTP client is the application's, and one that records what it sent (a history
     * middleware, a mock, a traceable client) holds the token and the identifiers in its requests.
     * var_export cannot show what a closure holds, and __debugInfo leaves it out of var_dump and print_r.
     *
     * @var Closure(): ClientInterface
     */
    private readonly Closure $http;

    public function __construct(
        ClientInterface $http,
        private readonly StreamFactoryInterface $streams,
    ) {
        $this->http = static fn (): ClientInterface => $http;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['http' => '[hidden]'];
    }

    /**
     * @param  bool  $preflight  true for calls made before the data request (login): a failure there
     *                           means the data request was never sent.
     */
    public function send(#[SensitiveParameter] RequestInterface $request, string $endpoint, bool $preflight = false): ResponseInterface
    {
        try {
            $response = ($this->http)()->sendRequest($request);
            $body = $response->getBody();

            if ($body->isSeekable()) {
                $body->rewind();
            }

            return $response->withBody($this->streams->createStream($body->getContents()));
        } catch (Throwable $e) {
            // A PSR-18 client should only throw ClientExceptionInterface, but a misbehaving one or a
            // failing body stream must not break the exception contract of this package either.
            // The HTTP client's own message may describe the request it was sending. The login carries
            // the password in its body, in whatever encoding the client prints it, so nothing of that
            // message is kept for it; the redaction of the others removes identifiers and tokens.
            throw new TransportException(
                "{$endpoint}: the HTTP client failed before a whole answer arrived (".$e::class.').',
                detail: $preflight ? null : self::withoutToken($e->getMessage(), $request),
                endpoint: $endpoint,
                requestSent: ! $preflight,
            );
        }
    }

    /**
     * The message without the token the request carried, whatever its shape: the redaction of
     * the exception only knows the usual JWT, and a client may print the headers it was sending.
     */
    private static function withoutToken(#[SensitiveParameter] string $message, #[SensitiveParameter] RequestInterface $request): string
    {
        $header = $request->getHeaderLine('Authorization');
        $token = trim((string) preg_replace('/^Bearer\s+/i', '', $header));
        $message = PersonalDataRedactor::withoutSecrets($message, array_values(array_filter([$header, $token], static fn (string $s): bool => $s !== '')));

        return (string) preg_replace('/\b(Authorization\s*[:=]\s*)\S+(?:\s+[A-Za-z0-9._~+\/=-]+)?|\bBearer\s+[A-Za-z0-9._~+\/=-]+/i', PersonalDataRedactor::PLACEHOLDER, $message);
    }
}
