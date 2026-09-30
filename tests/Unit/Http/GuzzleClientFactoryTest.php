<?php

declare(strict_types=1);

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Http\GuzzleClientFactory;
use Psr\Http\Client\ClientInterface;

function guzzleWith(MockHandler $mock, ?DatadisConfig $config = null): ClientInterface
{
    return GuzzleClientFactory::create(
        $config ?? new DatadisConfig('12345678Z', 'secret', baseUrl: 'https://datadis.test', timeout: 42.0, connectTimeout: 7.0),
        ['handler' => HandlerStack::create($mock)],
    );
}

it('applies the configured timeouts and disables Guzzle magic', function () {
    $mock = new MockHandler([new Response(200, [], '[]')]);

    guzzleWith($mock)->sendRequest(new Request('GET', 'https://datadis.test/x'));
    $options = $mock->getLastOptions();

    expect($options['timeout'])->toBe(42.0)
        ->and($options['connect_timeout'])->toBe(7.0)
        ->and($options['http_errors'])->toBeFalse()
        ->and($options['allow_redirects'])->toBeFalse()
        ->and($options['decode_content'])->toBeFalse();
});

it('returns error statuses as responses instead of throwing', function () {
    $mock = new MockHandler([new Response(500, [], ''), new Response(429, [], 'again')]);
    $client = guzzleWith($mock);

    expect($client->sendRequest(new Request('GET', 'https://datadis.test/x'))->getStatusCode())->toBe(500)
        ->and($client->sendRequest(new Request('GET', 'https://datadis.test/x'))->getStatusCode())->toBe(429);
});

it('does not add an Accept-Encoding header of its own', function () {
    $mock = new MockHandler([new Response(200, [], '[]')]);

    guzzleWith($mock)->sendRequest(new Request('GET', 'https://datadis.test/x'));

    expect($mock->getLastRequest()->hasHeader('Accept-Encoding'))->toBeFalse();
});
