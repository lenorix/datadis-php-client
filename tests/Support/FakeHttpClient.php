<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Tests\Support;

use Closure;
use LogicException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * PSR-18 test double: serves queued responses in order and records every request.
 *
 * A queued item is a response, a throwable (thrown when reached), or a closure
 * receiving the request and returning a response or throwing.
 * An unexpected request fails loudly instead of returning something invented.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    private array $requests = [];

    /** @var list<ResponseInterface|Throwable|Closure> */
    private array $queue = [];

    public function queue(ResponseInterface|Throwable|Closure ...$items): self
    {
        foreach ($items as $item) {
            $this->queue[] = $item;
        }

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $item = array_shift($this->queue);

        if ($item === null) {
            throw new LogicException(
                'Unexpected request with nothing queued: '.$request->getMethod().' '.$request->getUri()
            );
        }

        if ($item instanceof Closure) {
            $item = $item($request);
        }

        if ($item instanceof Throwable) {
            throw $item;
        }

        return $item;
    }

    /** @return list<RequestInterface> */
    public function requests(): array
    {
        return $this->requests;
    }

    public function lastRequest(): RequestInterface
    {
        return $this->requests[array_key_last($this->requests)]
            ?? throw new LogicException('No request was sent.');
    }

    public function pending(): int
    {
        return count($this->queue);
    }
}
