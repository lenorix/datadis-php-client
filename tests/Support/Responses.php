<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

/** Small builders for canned PSR-7 responses. */
final class Responses
{
    /** @param array<string, string> $headers */
    public static function json(string $body, int $status = 200, array $headers = []): ResponseInterface
    {
        return new Response($status, $headers + ['Content-Type' => 'application/json'], $body);
    }

    /** @param array<string, string> $headers */
    public static function text(string $body, int $status = 200, array $headers = []): ResponseInterface
    {
        return new Response($status, $headers + ['Content-Type' => 'text/plain;charset=UTF-8'], $body);
    }

    /**
     * A successful data answer as Datadis really sends it: JSON labelled `text/plain` (verified).
     *
     * @param  array<array-key, mixed>|string  $body
     */
    public static function datadis(array|string $body, int $status = 200): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'text/plain'], is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** A Datadis error: plain text labelled `application/json;charset=UTF-8`, as the real 400/403/404 are. */
    public static function datadisError(string $body, int $status): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json;charset=UTF-8'], $body);
    }

    public static function empty(int $status = 200): ResponseInterface
    {
        return new Response($status);
    }
}
