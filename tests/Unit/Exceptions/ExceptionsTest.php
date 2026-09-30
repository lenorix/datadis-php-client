<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Exceptions\AuthenticationException;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Lenorix\DatadisClient\Exceptions\InvalidRequestException;
use Lenorix\DatadisClient\Exceptions\LedgerUnavailableException;
use Lenorix\DatadisClient\Exceptions\RequestRejectedException;
use Lenorix\DatadisClient\Exceptions\TransportException;
use Lenorix\DatadisClient\Exceptions\UnsupportedOperationException;

it('assumes the request was sent unless it is a pre-flight failure', function () {
    expect((new RequestRejectedException('x'))->requestSent)->toBeTrue()
        ->and((new TransportException('x'))->requestSent)->toBeTrue()
        ->and((new ConfigurationException('x'))->requestSent)->toBeFalse()
        ->and((new InvalidRequestException('x'))->requestSent)->toBeFalse()
        ->and((new LedgerUnavailableException('x'))->requestSent)->toBeFalse()
        ->and((new UnsupportedOperationException('x'))->requestSent)->toBeFalse()
        ->and((new AuthenticationException('x', requestSent: false))->requestSent)->toBeFalse();
});

it('carries the status, the endpoint and the redacted detail', function () {
    $exception = new RequestRejectedException(
        'rejected',
        httpStatus: 400,
        detail: 'bad value',
        endpoint: 'get-supplies-v2',
    );

    expect($exception->httpStatus)->toBe(400)
        ->and($exception->endpoint)->toBe('get-supplies-v2')
        ->and($exception->detail)->toBe('bad value');
});

it('redacts identifiers from the message and the detail whatever the caller passes', function () {
    $exception = new RequestRejectedException(
        'value ES0031300000000001JN0F rejected for 12345678Z',
        detail: 'echo ES0031300000000001JN and X1234567L',
    );

    expect($exception->getMessage())->not->toContain('ES0031300000000001JN0F')->not->toContain('12345678Z')
        ->and($exception->detail)->not->toContain('ES0031300000000001JN')->not->toContain('X1234567L');
});

it('keeps the previous exception', function () {
    $previous = new LogicException('inner');

    expect((new TransportException('x', previous: $previous))->getPrevious())->toBe($previous);
});
