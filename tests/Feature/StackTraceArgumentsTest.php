<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Exceptions\DatadisException;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Lenorix\DatadisClient\Tests\Support\Scenario;
use Lenorix\DatadisClient\Tests\Support\Tokens;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\Nif;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

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
            $s->client->getConsumptionData(Cups::fromString('ES0031300000000001JN0F'), '2', 5, Month::of(2026, 1), Month::of(2026, 1), authorizedNif: Nif::fromString('87654321X'));
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

it('keeps personal data of decoded answers out of stack trace arguments', function () {
    $previous = ini_set('zend.exception_ignore_args', '0');

    try {
        $s = Scenario::make();
        $s->http->queue(Responses::datadis('{"timeCurve":[{"cups":"ES0031300000000001JN0F","date":"x","time":"01:00","consumptionKWh":null}]}'));

        try {
            $s->client->getConsumptionData(Cups::fromString('ES0031300000000001JN0F'), '2', 5, Month::of(2026, 1), Month::of(2026, 1));
        } catch (DatadisException $e) {
            $strings = traceStrings(array_map(fn (array $frame) => $frame['args'] ?? [], $e->getTrace()));

            expect(implode("\n", $strings))->not->toContain('ES0031300000000001JN0F');

            return;
        }

        throw new LogicException('Expected an exception.');
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previous);
    }
});

it('never shows the token or the password when the client is dumped', function () {
    // An HTTP client that, like a real one, keeps nothing of the requests it sent.
    $http = new class implements ClientInterface
    {
        /** @var list<ResponseInterface> */
        public array $answers = [];

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            return array_shift($this->answers);
        }
    };
    $token = Tokens::jwt(['exp' => time() + 3600]);
    $http->answers = [Responses::text($token), Responses::datadis('{"supplies":[]}')];
    $client = new DatadisClient(new DatadisConfig('12345678Z', 'never-dump-this', baseUrl: 'https://datadis.test'), http: $http);
    $client->getSupplies();

    ob_start();
    var_dump($client);
    $dumps = (string) ob_get_clean().print_r($client, true).var_export($client, true);

    expect($dumps)->not->toContain($token)->not->toContain('never-dump-this');
});
