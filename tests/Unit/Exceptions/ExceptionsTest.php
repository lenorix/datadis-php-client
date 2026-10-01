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
        'value ES0000000000000000AA0A rejected for A00000000',
        detail: 'echo ES0000000000000000AA and X0000000A',
    );

    expect($exception->getMessage())->not->toContain('ES0000000000000000AA0A')->not->toContain('A00000000')
        ->and($exception->detail)->not->toContain('ES0000000000000000AA')->not->toContain('X0000000A');
});

it('keeps the previous exception', function () {
    $previous = new LogicException('inner');

    expect((new TransportException('x', previous: $previous))->getPrevious())->toBe($previous);
});
