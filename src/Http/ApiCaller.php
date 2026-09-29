<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Http;

use Lenorix\DatadisClient\Auth\TokenProvider;

/**
 * Makes an authenticated GET and returns the decoded JSON.
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
     * @param  array<string, string|int|null>  $query
     * @return array<array-key, mixed>
     */
    public function get(string $path, array $query, string $endpoint): array
    {
        $response = $this->transport->send($this->requests->get($path, $query, $this->tokens->token()), $endpoint);

        if ($response->getStatusCode() === 401) {
            $this->tokens->invalidate();
            $response = $this->transport->send($this->requests->get($path, $query, $this->tokens->token()), $endpoint);
        }

        return ResponseClassifier::decode($response, $endpoint);
    }
}
