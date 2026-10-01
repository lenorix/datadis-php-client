<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Lenorix\DatadisClient\Exceptions\DatadisException;
use Lenorix\DatadisClient\Guard\RequestFingerprinter;
use Lenorix\DatadisClient\Guard\RequestLedger;
use Lenorix\DatadisClient\PublicApi\Community;
use Lenorix\DatadisClient\PublicApi\PublicSearchQuery;
use Lenorix\DatadisClient\PublicApiClient;
use Lenorix\DatadisClient\Tests\Support\AtomicCache;
use Lenorix\DatadisClient\Tests\Support\QuirkyCache;
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
            $s->client->getConsumptionData(Cups::fromString('ES0000000000000000AA0A'), '2', 5, Month::of(2026, 1), Month::of(2026, 1), authorizedNif: Nif::fromString('00000000T'));
        } catch (DatadisException $e) {
            $strings = traceStrings(array_map(fn (array $frame) => $frame['args'] ?? [], $e->getTrace()));

            expect(implode("\n", $strings))->not->toContain('ES0000000000000000AA0A')->not->toContain('00000000T');

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
        $s->http->queue(Responses::datadis('{"timeCurve":[{"cups":"ES0000000000000000AA0A","date":"x","time":"01:00","consumptionKWh":null}]}'));

        try {
            $s->client->getConsumptionData(Cups::fromString('ES0000000000000000AA0A'), '2', 5, Month::of(2026, 1), Month::of(2026, 1));
        } catch (DatadisException $e) {
            $strings = traceStrings(array_map(fn (array $frame) => $frame['args'] ?? [], $e->getTrace()));

            expect(implode("\n", $strings))->not->toContain('ES0000000000000000AA0A');

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
    $client = new DatadisClient(new DatadisConfig('A00000000', 'never-dump-this', baseUrl: 'https://datadis.test'), http: $http);
    $client->getSupplies();

    ob_start();
    var_dump($client);
    $dumps = (string) ob_get_clean().print_r($client, true).var_export($client, true);

    expect($dumps)->not->toContain($token)->not->toContain('never-dump-this');
});

it('never shows the token when the client or the public client is dumped with a cache of yours that shows what it holds', function (bool $public) {
    // A PSR-16 store that keeps its values in a public property, as a simple array cache may, and
    // is shared with the 24 hour ledger as in the Laravel recipe.
    $cache = new QuirkyCache;
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
    $http->answers = [Responses::text($token), Responses::datadis($public ? '[]' : '{"supplies":[]}')];
    $config = new DatadisConfig('A00000000', 'never-dump-this', baseUrl: 'https://datadis.test');
    $ledger = new RequestLedger($cache, new RequestFingerprinter('a-secret-key-of-at-least-32-bytes!!'), atomic: new AtomicCache);
    $client = $public
        ? new PublicApiClient($config, $http, tokenCache: $cache)
        : new DatadisClient($config, http: $http, tokenCache: $cache, ledger: $ledger);
    $public ? $client->apiSearch(new PublicSearchQuery(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'), [Community::Madrid])) : $client->getSupplies();

    ob_start();
    var_dump($client, $ledger);
    debug_zval_dump($client);
    $dumps = (string) ob_get_clean().print_r($client, true).var_export($client, true).print_r($ledger, true).var_export($ledger, true);

    expect(print_r($cache, true))->toContain($token)
        ->and($dumps)->not->toContain($token)->not->toContain('never-dump-this');
})->with(['private API' => false, 'public API' => true]);

it('keeps the password out of stack trace arguments when a setting is wrong', function () {
    $previous = ini_set('zend.exception_ignore_args', '0');

    try {
        DatadisClient::fromArray(['username' => 'A00000000', 'password' => 'never-show-this', 'timeout' => '30s']);
    } catch (ConfigurationException $e) {
        expect(implode("\n", traceStrings(array_map(fn (array $frame) => $frame['args'] ?? [], $e->getTrace()))))->not->toContain('never-show-this');

        return;
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previous);
    }

    throw new LogicException('Expected a ConfigurationException.');
});
