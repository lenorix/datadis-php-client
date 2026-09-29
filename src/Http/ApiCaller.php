<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Http;

use Lenorix\DatadisClient\Auth\TokenProvider;
use Psr\Http\Message\ResponseInterface;

/**
 * Makes an authenticated GET.
 *
 * A 401 means the token was rejected: the token is dropped, one new login is made and the call is
 * repeated once. A second 401 is final. A network failure is never retried here, because it may
 * have reached Datadis and Datadis refuses an identical query for 24 hours.
 */
final class ApiCaller
{
    public function __construct(
        private readonly RequestFactory $requests,
        private readonly Transport $transport,
        private readonly TokenProvider $tokens,
    ) {}

    /**
     * @param  array<string, string|int|list<string>|null>  $query
     * @return array<array-key, mixed> the decoded JSON
     */
    public function get(string $path, array $query, string $endpoint): array
    {
        return ResponseClassifier::decode($this->send($path, $query, $endpoint), $endpoint);
    }

    /**
     * The body of a successful answer as text, possibly empty. For endpoints without a documented body.
     *
     * @param  array<string, string|int|list<string>|null>  $query
     */
    public function getText(string $path, array $query, string $endpoint): string
    {
        return ResponseClassifier::assertSuccessful($this->send($path, $query, $endpoint), $endpoint);
    }

    /** @param  array<string, string|int|list<string>|null>  $query */
    private function send(string $path, array $query, string $endpoint): ResponseInterface
    {
        $response = $this->transport->send($this->requests->get($path, $query, $this->tokens->token()), $endpoint);

        if ($response->getStatusCode() === 401) {
            $this->tokens->invalidate();
            $response = $this->transport->send($this->requests->get($path, $query, $this->tokens->token()), $endpoint);
        }

        return $response;
    }
}
