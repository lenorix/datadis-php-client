<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Http;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Lenorix\DatadisClient\Auth\SystemClock;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Opt-in PSR-18 decorator that retries transient failures of the endpoints where a repeat is harmless.
 *
 * It retries network failures and 502, 503 and 504 answers of the login, supplies, distributors,
 * contract detail, authorization list and public API calls, with exponential backoff and jitter,
 * honouring Retry-After when it is not longer than the maximum wait.
 *
 * It never retries the guarded endpoints (consumption, max power, reactive) nor the authorization
 * changes: a request that may have reached Datadis burns the 24 hour repetition window, and a
 * network failure cannot tell whether it did. It never retries 4xx (429 included) nor a plain 500,
 * which Datadis answers consistently for some supplies.
 *
 * Usage: new DatadisClient($config, http: new RetryingClient(GuzzleClientFactory::create($config))).
 */
final class RetryingClient implements ClientInterface
{
    private const array RETRYABLE_STATUSES = [502, 503, 504];

    /** Path fragments of the calls that must never be repeated automatically. */
    private const array NEVER_RETRY = [
        '/get-consumption-data',
        '/get-max-power',
        '/get-reactive-data',
        '/new-authorization',
        '/cancel-authorization',
    ];

    private readonly Closure $sleep;

    private readonly Closure $random;

    private readonly ClockInterface $clock;

    /**
     * @param  Closure(int): void|null  $sleep  receives milliseconds; defaults to usleep
     * @param  Closure(): float|null  $random  a number between 0 and 1 for the jitter
     */
    public function __construct(
        private readonly ClientInterface $inner,
        private readonly int $maxRetries = 2,
        private readonly int $baseDelayMs = 1000,
        private readonly int $maxDelayMs = 30000,
        ?Closure $sleep = null,
        ?Closure $random = null,
        ?ClockInterface $clock = null,
    ) {
        if ($maxRetries < 0 || $maxRetries > 10) {
            throw new InvalidArgumentException('The number of retries must be between 0 and 10.');
        }

        if ($baseDelayMs < 1 || $maxDelayMs < $baseDelayMs) {
            throw new InvalidArgumentException('Delays must be positive and the maximum not below the base.');
        }

        $this->sleep = $sleep ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
        $this->random = $random ?? static fn (): float => mt_rand() / mt_getrandmax();
        $this->clock = $clock ?? new SystemClock;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if (! $this->mayRetry($request)) {
            return $this->inner->sendRequest($request);
        }

        for ($attempt = 0; ; $attempt++) {
            $body = $request->getBody();

            if ($body->isSeekable()) {
                $body->rewind();
            }

            try {
                $response = $this->inner->sendRequest($request);
            } catch (NetworkExceptionInterface $e) {
                if ($attempt >= $this->maxRetries) {
                    throw $e;
                }

                ($this->sleep)($this->backoff($attempt));

                continue;
            }

            if ($attempt >= $this->maxRetries || ! in_array($response->getStatusCode(), self::RETRYABLE_STATUSES, true)) {
                return $response;
            }

            $delay = $this->retryAfter($response) ?? $this->backoff($attempt);

            if ($delay > $this->maxDelayMs) {
                return $response;
            }

            ($this->sleep)($delay);
        }
    }

    private function mayRetry(RequestInterface $request): bool
    {
        $path = $request->getUri()->getPath();

        foreach (self::NEVER_RETRY as $fragment) {
            if (str_contains($path, $fragment)) {
                return false;
            }
        }

        return $request->getMethod() === 'GET' || str_ends_with($path, RequestFactory::LOGIN_PATH);
    }

    /** Exponential, capped, with "equal jitter": between half and all of the step. */
    private function backoff(int $attempt): int
    {
        $step = min($this->maxDelayMs, $this->baseDelayMs * (2 ** min($attempt, 30)));
        $random = max(0.0, min(1.0, ($this->random)()));

        return (int) round($step * (0.5 + 0.5 * $random));
    }

    /** Milliseconds requested by a Retry-After header (seconds or an HTTP date), or null when absent or unreadable. */
    private function retryAfter(ResponseInterface $response): ?int
    {
        $value = trim($response->getHeaderLine('Retry-After'));

        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{1,7}$/D', $value) === 1) {
            return (int) $value * 1000;
        }

        // The format has GMT as a literal, so the zone must be given or the host default would be used.
        $date = DateTimeImmutable::createFromFormat(DATE_RFC7231, $value, new DateTimeZone('GMT'));

        if ($date === false) {
            return null;
        }

        return max(0, ($date->getTimestamp() - $this->clock->now()->getTimestamp()) * 1000);
    }
}
