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
use Lenorix\DatadisClient\Tests\Support\FakeHttpClient;
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

/** Every string reachable from trace arguments: scalars, arrays, request URIs, and objects as dumps show them. */
function traceStrings(mixed $value): array
{
    return match (true) {
        is_string($value) => [$value],
        is_array($value) => array_merge([], ...array_map('traceStrings', array_values($value))),
        $value instanceof RequestInterface => [(string) $value->getUri(), (string) $value->getBody()],
        is_object($value) => [print_r($value, true), var_export($value, true)],
        default => [],
    };
}

/**
 * The arguments recorded in a trace for calls into the package and calls the package makes, PHP's own
 * functions included. The other frames of the test and of the test runner hold the test's own objects,
 * which may show what they sent.
 */
function packageArgs(Throwable $e): array
{
    $src = dirname(__DIR__, 2).'/src/';
    $ours = fn (array $frame) => str_starts_with($frame['file'] ?? '', $src)
        || (str_starts_with($frame['class'] ?? '', 'Lenorix\\DatadisClient\\') && ! str_starts_with($frame['class'] ?? '', 'Lenorix\\DatadisClient\\Tests\\'));

    return array_map(fn (array $frame) => $frame['args'] ?? [], array_values(array_filter($e->getTrace(), $ours)));
}

/** The trace strings of an exception and of every exception it chains. */
function chainTraceStrings(Throwable $e): string
{
    $strings = [];

    for (; $e !== null; $e = $e->getPrevious()) {
        $strings = [...$strings, ...traceStrings(packageArgs($e))];
    }

    return implode("\n", $strings);
}

/** Runs $call with arguments recorded in traces and returns the trace strings of what it throws. */
function tracesOf(Closure $call): string
{
    $previous = ini_set('zend.exception_ignore_args', '0');

    try {
        $call();
    } catch (Throwable $e) {
        return chainTraceStrings($e);
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previous);
    }

    throw new LogicException('Expected an exception.');
}

it('keeps CUPS, NIF and credentials out of the arguments recorded in stack traces', function () {
    $previous = ini_set('zend.exception_ignore_args', '0');

    try {
        $s = Scenario::make();
        $s->http->queue(new ConnectException('timeout', new Request('GET', 'https://datadis.test')));

        try {
            $s->client->getConsumptionData(Cups::fromString('ES0000000000000000AA0A'), '2', 5, Month::of(2026, 1), Month::of(2026, 1), authorizedNif: Nif::fromString('00000000T'));
        } catch (DatadisException $e) {
            $strings = traceStrings(packageArgs($e));

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
            $strings = traceStrings(packageArgs($e));

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
        expect(implode("\n", traceStrings(packageArgs($e))))->not->toContain('never-show-this');

        return;
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previous);
    }

    throw new LogicException('Expected a ConfigurationException.');
});

it('keeps the account NIF out of every dump of the client', function () {
    $ledger = new RequestLedger(new QuirkyCache, new RequestFingerprinter('a-secret-key-of-at-least-32-bytes!!'));
    $client = new DatadisClient(new DatadisConfig('A00000000', 'secret', baseUrl: 'https://datadis.test'), ledger: $ledger);

    ob_start();
    var_dump($client);
    debug_zval_dump($client);

    expect((string) ob_get_clean().print_r($client, true).var_export($client, true))->not->toContain('A00000000');
});

it('keeps the NIF of a delegated holder out of every dump of the client and of trace arguments', function () {
    $previous = ini_set('zend.exception_ignore_args', '0');

    try {
        $s = Scenario::make();
        $client = $s->client->forHolder(Nif::fromString('00000000T'));

        ob_start();
        var_dump($client);
        debug_zval_dump($client);
        $dumps = (string) ob_get_clean().print_r($client, true).var_export($client, true);

        $s->http->queue(new ConnectException('timeout', new Request('GET', 'https://datadis.test')));

        try {
            $client->getSupplies(Nif::fromString('00000000T'));
        } catch (DatadisException $e) {
            // Every argument, objects included, as a dump would show it.
            $args = packageArgs($e);
            $dumps .= print_r($args, true).var_export($args, true);
        }

        expect($dumps)->not->toContain('00000000T')->toContain('[hidden]');
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previous);
    }
});

it('keeps a body that is not valid JSON out of the traces, chained exceptions included', function () {
    $s = Scenario::make();
    $s->http->queue(Responses::datadis('{"supplies":[{"cups":"ES0000000000000000AA0A","address":"CALLE FALSA 1"},'));

    expect(tracesOf(fn () => $s->client->getSupplies()))->not->toContain('ES0000000000000000AA0A')->not->toContain('CALLE FALSA');
});

it('keeps the account NIF out of the traces when the ledger store fails', function (Closure $cache) {
    $ledger = new RequestLedger($cache(), new RequestFingerprinter('a-secret-key-of-at-least-32-bytes!!'));
    $client = new DatadisClient(new DatadisConfig('A00000000', 'secret', baseUrl: 'https://datadis.test'), http: new FakeHttpClient, ledger: $ledger);

    expect(tracesOf(fn () => $client->getMaxPower(Cups::fromString('ES0000000000000000AA0A'), '2', Month::of(2026, 1))))
        ->not->toContain('A00000000')->not->toContain('ES0000000000000000AA0A');
})->with([
    'unreadable' => [fn () => new QuirkyCache(throwOnGet: true)],
    'refusing to record' => [fn () => new QuirkyCache(failSet: true)],
]);

it('keeps a NIF, NIE, CIF or CUPS that is refused out of the traces', function (Closure $call, string $value) {
    expect(tracesOf($call))->not->toContain($value);
})->with([
    'a username with a wrong control letter' => [fn () => new DatadisConfig('00000000R', 'secret'), '00000000R'],
    'a Nif with a wrong control letter' => [fn () => Nif::fromString('00000000R'), '00000000R'],
    'a CUPS of the wrong shape' => [fn () => Cups::fromString('ES0000000000000000A'), 'ES0000000000000000A'],
]);

it('keeps a CUPS out of every dump of the traces of a failed call', function () {
    $s = Scenario::make();
    $s->http->queue(new ConnectException('timeout', new Request('GET', 'https://datadis.test')));

    expect(tracesOf(fn () => $s->client->getMaxPower(Cups::fromString('ES0000000000000000AA0A'), '2', Month::of(2026, 1))))
        ->not->toContain('ES0000000000000000AA0A');
});

it('keeps a NIF given as the time zone out of the message, the chained exceptions and the traces', function () {
    $settings = ['username' => 'A00000000', 'password' => 'secret', 'timezone' => '00000000T'];

    try {
        DatadisClient::fromArray($settings);
    } catch (ConfigurationException $e) {
        $all = '';

        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            $all .= $x->getMessage()."\n";
        }

        expect($all)->not->toContain('00000000T')->toContain('timezone')
            ->and(tracesOf(fn () => DatadisClient::fromArray($settings)))->not->toContain('00000000T');

        return;
    }

    throw new LogicException('Expected a ConfigurationException.');
});
