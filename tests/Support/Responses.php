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
        return new Response($status, ['Content-Type' => 'application/json'] + $headers, $body);
    }

    /** @param array<string, string> $headers */
    public static function text(string $body, int $status = 200, array $headers = []): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'text/plain;charset=UTF-8'] + $headers, $body);
    }

    public static function empty(int $status = 200): ResponseInterface
    {
        return new Response($status);
    }
}
