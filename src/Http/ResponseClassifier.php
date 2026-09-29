<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Http;

use JsonException;
use Lenorix\DatadisClient\Exceptions\AuthenticationException;
use Lenorix\DatadisClient\Exceptions\AuthorizationException;
use Lenorix\DatadisClient\Exceptions\DatadisException;
use Lenorix\DatadisClient\Exceptions\NoDataException;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\DatadisClient\Exceptions\RequestRejectedException;
use Lenorix\DatadisClient\Exceptions\ServiceUnavailableException;
use Lenorix\DatadisClient\Exceptions\UninterpretableResponseException;
use Lenorix\DatadisClient\Support\PersonalDataRedactor;
use Psr\Http\Message\ResponseInterface;

/**
 * Turns a PSR-7 response into decoded JSON or the exception that describes the failure.
 *
 * Status meanings come from real use, see docs/quirks-and-rules.md. A 2xx with an empty list is a
 * success and is returned as such; deciding what an empty list means belongs to the endpoint layer.
 */
final class ResponseClassifier
{
    /** Upper bound for an inflated body, to stay safe against decompression bombs. */
    private const int MAX_INFLATED_BYTES = 32 * 1024 * 1024;

    private const int DETAIL_LENGTH = 300;

    /**
     * @return array<array-key, mixed> the decoded JSON object or list
     *
     * @throws DatadisException
     */
    public static function decode(ResponseInterface $response, string $endpoint): array
    {
        $status = $response->getStatusCode();
        $body = self::readBody($response);

        if ($status < 200 || $status >= 300) {
            throw self::failure($status, $body, $endpoint);
        }

        if ($status === 204 || trim($body) === '') {
            throw new NoDataException("{$endpoint}: Datadis answered without a body.", $status, '', $endpoint);
        }

        try {
            $decoded = json_decode(trim($body), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new UninterpretableResponseException(
                "{$endpoint}: the response is not valid JSON.",
                $status,
                self::detail($body),
                $endpoint,
                previous: $e,
            );
        }

        if (! is_array($decoded)) {
            throw new UninterpretableResponseException(
                "{$endpoint}: the response is not a JSON object or list.",
                $status,
                self::detail($body),
                $endpoint,
            );
        }

        return $decoded;
    }

    private static function failure(int $status, string $body, string $endpoint): DatadisException
    {
        $detail = self::detail($body);
        $message = "{$endpoint}: Datadis answered HTTP {$status}".($detail === '' ? '.' : " · {$detail}");

        return match (true) {
            $status === 401 => new AuthenticationException($message, $status, $detail, $endpoint),
            $status === 403 => new AuthorizationException($message, $status, $detail, $endpoint),
            $status === 404 => new NoDataException($message, $status, $detail, $endpoint),
            $status === 429 => new RepetitionWindowException($message, $status, $detail, $endpoint),
            $status >= 500 => new ServiceUnavailableException($message, $status, $detail, $endpoint),
            $status >= 400 => new RequestRejectedException($message, $status, $detail, $endpoint),
            default => new UninterpretableResponseException($message, $status, $detail, $endpoint),
        };
    }

    /** The body as text: inflated when it is gzip whatever its headers claim, without a byte order mark. */
    private static function readBody(ResponseInterface $response): string
    {
        $body = (string) $response->getBody();

        if (str_starts_with($body, "\x1f\x8b")) {
            // Some responses are labelled gzip without being gzip and the reverse. A failed inflate
            // simply leaves the body as it came. gzdecode() warns on bad data, so the warning is
            // absorbed for this one call only.
            set_error_handler(static fn (): bool => true);

            try {
                $inflated = gzdecode($body, self::MAX_INFLATED_BYTES);
            } finally {
                restore_error_handler();
            }

            if ($inflated !== false) {
                $body = $inflated;
            }
        }

        return str_starts_with($body, "\xEF\xBB\xBF") ? substr($body, 3) : $body;
    }

    /**
     * Error bodies are `text/plain`, Spring style JSON (`{"timestamp","status","error","message","path"}`)
     * or `{"message": "..."}`. Whatever it is, the result is a redacted single-line excerpt.
     */
    private static function detail(string $body): string
    {
        $text = trim($body);

        if (str_starts_with($text, '{')) {
            try {
                $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $decoded = null;
            }

            if (is_array($decoded) && isset($decoded['message']) && is_string($decoded['message'])) {
                $text = $decoded['message'];
            }
        }

        return PersonalDataRedactor::excerpt($text, self::DETAIL_LENGTH);
    }
}
