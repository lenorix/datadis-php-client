<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Lenorix\DatadisClient\Exceptions\DatadisException;
use Lenorix\DatadisClient\Tests\Support\Scenario;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\Nif;
use Psr\Http\Message\RequestInterface;

/** Every string reachable from trace arguments: scalars, arrays and request URIs. */
function traceStrings(mixed $value): array
{
    return match (true) {
        is_string($value) => [$value],
        is_array($value) => array_merge([], ...array_map('traceStrings', array_values($value))),
        $value instanceof RequestInterface => [(string) $value->getUri(), (string) $value->getBody()],
        default => [],
    };
}

it('keeps CUPS, NIF and credentials out of the arguments recorded in stack traces', function () {
    $previous = ini_set('zend.exception_ignore_args', '0');

    try {
        $s = Scenario::make();
        $s->http->queue(new ConnectException('timeout', new Request('GET', 'https://datadis.test')));

        try {
            $s->client->consumption(Cups::fromString('ES0031300000000001JN0F'), '2', 5, Month::of(2026, 1), Month::of(2026, 1), authorizedNif: Nif::fromString('87654321X'));
        } catch (DatadisException $e) {
            $strings = traceStrings(array_map(fn (array $frame) => $frame['args'] ?? [], $e->getTrace()));

            expect(implode("\n", $strings))->not->toContain('ES0031300000000001JN0F')->not->toContain('87654321X');

            return;
        }

        throw new LogicException('Expected an exception.');
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previous);
    }
});
