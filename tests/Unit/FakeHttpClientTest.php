<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request;
use Lenorix\DatadisClient\Tests\Support\FakeHttpClient;
use Lenorix\DatadisClient\Tests\Support\Responses;

it('serves queued responses in order and records requests', function () {
    $http = (new FakeHttpClient)->queue(Responses::text('one'), Responses::text('two'));

    $first = $http->sendRequest(new Request('GET', 'https://datadis.test/a'));
    $second = $http->sendRequest(new Request('GET', 'https://datadis.test/b'));

    expect((string) $first->getBody())->toBe('one')
        ->and((string) $second->getBody())->toBe('two')
        ->and($http->requests())->toHaveCount(2)
        ->and((string) $http->lastRequest()->getUri())->toBe('https://datadis.test/b')
        ->and($http->pending())->toBe(0);
});

it('throws queued throwables and runs closures', function () {
    $http = (new FakeHttpClient)->queue(
        new RuntimeException('boom'),
        fn ($request) => Responses::text($request->getMethod()),
    );

    expect(fn () => $http->sendRequest(new Request('GET', 'https://datadis.test/')))->toThrow(RuntimeException::class, 'boom');
    expect((string) $http->sendRequest(new Request('POST', 'https://datadis.test/'))->getBody())->toBe('POST');
});

it('fails loudly on an unexpected request', function () {
    (new FakeHttpClient)->sendRequest(new Request('GET', 'https://datadis.test/'));
})->throws(LogicException::class, 'Unexpected request');
