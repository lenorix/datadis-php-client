<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\HttpFactory;
use Lenorix\DatadisClient\ConnectionSettings;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Exceptions\TransportException;
use Lenorix\DatadisClient\Http\GuzzleClientFactory;
use Lenorix\DatadisClient\Http\RequestFactory;
use Lenorix\DatadisClient\Http\Transport;
use Lenorix\DatadisClient\PublicApi\Community;
use Lenorix\DatadisClient\PublicApi\PublicApi;
use Lenorix\DatadisClient\PublicApi\PublicSearchQuery;

it('turns a real Guzzle connection failure into a TransportException without leaking the url', function () {
    // Port 1 on localhost refuses connections immediately, so no external network is involved.
    $config = new DatadisConfig('12345678Z', 'secret', baseUrl: 'https://127.0.0.1:1', timeout: 5.0, connectTimeout: 2.0);
    $factory = new HttpFactory;
    $transport = new Transport(GuzzleClientFactory::create($config), $factory);
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

it('builds a working Guzzle client when none is given', function (Closure $call, bool $sent) {
    // Port 1 on localhost refuses connections immediately, so no external network is involved.
    try {
        $call();
    } catch (TransportException $e) {
        expect($e->requestSent)->toBe($sent);

        return;
    }

    throw new LogicException('Expected a TransportException.');
})->with([
    'private API, whose login fails before any data call' => [
        fn () => (new DatadisClient(new DatadisConfig('12345678Z', 'secret', baseUrl: 'https://127.0.0.1:1', timeout: 5.0, connectTimeout: 2.0)))->supplies(),
        false,
    ],
    'public API' => [
        fn () => (new PublicApi(new ConnectionSettings(baseUrl: 'https://127.0.0.1:1', timeout: 5.0, connectTimeout: 2.0)))
            ->search(new PublicSearchQuery(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'), [Community::Madrid])),
        true,
    ],
]);
