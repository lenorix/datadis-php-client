<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\HttpFactory;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Exceptions\TransportException;
use Lenorix\DatadisClient\Http\GuzzleClientFactory;
use Lenorix\DatadisClient\Http\RequestFactory;
use Lenorix\DatadisClient\Http\Transport;

it('turns a real Guzzle connection failure into a TransportException without leaking the url', function () {
    // Port 1 on localhost refuses connections immediately, so no external network is involved.
    $config = new DatadisConfig('12345678Z', 'secret', baseUrl: 'https://127.0.0.1:1', timeout: 5.0, connectTimeout: 2.0);
    $factory = new HttpFactory;
    $transport = new Transport(GuzzleClientFactory::create($config));
    $request = (new RequestFactory($config, $factory, $factory))
        ->get('/api-private/api/get-supplies-v2', ['cups' => 'ES0031300000000001JN0F', 'authorizedNif' => '87654321X'], 'token');

    try {
        $transport->send($request, 'get-supplies-v2');
    } catch (TransportException $e) {
        expect($e->requestSent)->toBeTrue()
            ->and($e->getPrevious())->toBeNull()
            ->and($e->getMessage().$e->detail)->not->toContain('ES0031300000000001JN0F')->not->toContain('87654321X');

        return;
    }

    throw new LogicException('Expected a TransportException.');
});
