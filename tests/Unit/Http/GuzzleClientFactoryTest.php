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
        $config ?? new DatadisConfig('A00000000', 'secret', baseUrl: 'https://datadis.test', timeout: 42.0, connectTimeout: 7.0),
        ['handler' => HandlerStack::create($mock)],
    );
}

it('applies the configured timeouts and leaves gzip to the package', function () {
    $mock = new MockHandler([new Response(200, [], '[]')]);

    guzzleWith($mock)->sendRequest(new Request('GET', 'https://datadis.test/x'));
    $options = $mock->getLastOptions();

    expect($options['timeout'])->toBe(42.0)
        ->and($options['connect_timeout'])->toBe(7.0)
        ->and($options['decode_content'])->toBeFalse();
});

it('does not add an Accept-Encoding header of its own', function () {
    $mock = new MockHandler([new Response(200, [], '[]')]);

    guzzleWith($mock)->sendRequest(new Request('GET', 'https://datadis.test/x'));

    expect($mock->getLastRequest()->hasHeader('Accept-Encoding'))->toBeFalse();
});
