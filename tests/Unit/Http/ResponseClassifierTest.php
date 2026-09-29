<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Lenorix\DatadisClient\Exceptions\AuthenticationException;
use Lenorix\DatadisClient\Exceptions\AuthorizationException;
use Lenorix\DatadisClient\Exceptions\NoDataException;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\DatadisClient\Exceptions\RequestRejectedException;
use Lenorix\DatadisClient\Exceptions\ServiceUnavailableException;
use Lenorix\DatadisClient\Exceptions\UninterpretableResponseException;
use Lenorix\DatadisClient\Http\ResponseClassifier;
use Lenorix\DatadisClient\Tests\Support\Responses;

const ENDPOINT = 'get-consumption-data-v2';

it('decodes a JSON object', function () {
    $decoded = ResponseClassifier::decode(Responses::json('{"timeCurve":[{"a":1}],"distributorError":[]}'), ENDPOINT);

    expect($decoded)->toBe(['timeCurve' => [['a' => 1]], 'distributorError' => []]);
});

it('decodes a JSON list, including an empty one', function () {
    expect(ResponseClassifier::decode(Responses::json('[]'), ENDPOINT))->toBe([])
        ->and(ResponseClassifier::decode(Responses::json('[{"cups":"x"}]'), ENDPOINT))->toBe([['cups' => 'x']]);
});

it('tolerates a byte order mark and surrounding whitespace', function () {
    expect(ResponseClassifier::decode(Responses::json("\xEF\xBB\xBF \n[1]\n"), ENDPOINT))->toBe([1]);
});

it('inflates a gzip body that is not labelled as gzip', function () {
    expect(ResponseClassifier::decode(Responses::json((string) gzencode('{"a":1}')), ENDPOINT))->toBe(['a' => 1]);
});

it('keeps a plain body whose bytes only look like a broken gzip header', function () {
    expect(fn () => ResponseClassifier::decode(Responses::json("\x1f\x8bnot really gzip"), ENDPOINT))
        ->toThrow(UninterpretableResponseException::class);
});

it('maps empty bodies and 204 to no data', function (Response $response) {
    ResponseClassifier::decode($response, ENDPOINT);
})->with([
    '200 empty' => fn () => Responses::empty(200),
    '200 blank' => fn () => Responses::text("  \n"),
    '204' => fn () => Responses::empty(204),
])->throws(NoDataException::class);

it('maps unusable 200 bodies to an uninterpretable response', function (string $body) {
    ResponseClassifier::decode(Responses::json($body), ENDPOINT);
})->with([
    'html maintenance page' => '<html><body>Mantenimiento</body></html>',
    'json string' => '"maintenance"',
    'json number' => '42',
    'json null' => 'null',
    'json true' => 'true',
    'truncated json' => '{"timeCurve":[',
])->throws(UninterpretableResponseException::class);

it('maps each error status to its exception', function (int $status, string $class) {
    $thrown = null;

    try {
        ResponseClassifier::decode(Responses::text('boom', $status), ENDPOINT);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf($class)
        ->and($thrown->httpStatus)->toBe($status)
        ->and($thrown->endpoint)->toBe(ENDPOINT)
        ->and($thrown->requestSent)->toBeTrue();
})->with([
    [400, RequestRejectedException::class],
    [401, AuthenticationException::class],
    [403, AuthorizationException::class],
    [404, NoDataException::class],
    [409, RequestRejectedException::class],
    [422, RequestRejectedException::class],
    [429, RepetitionWindowException::class],
    [500, ServiceUnavailableException::class],
    [502, ServiceUnavailableException::class],
    [503, ServiceUnavailableException::class],
    [504, ServiceUnavailableException::class],
    [301, UninterpretableResponseException::class],
]);

it('reads the message of Spring style and plain message error bodies', function (string $body) {
    try {
        ResponseClassifier::decode(Responses::json($body, 400), ENDPOINT);
    } catch (RequestRejectedException $e) {
        expect($e->detail)->toContain('Date range not allowed');

        return;
    }

    throw new LogicException('Expected an exception.');
})->with([
    'spring' => '{"timestamp":"2026-01-01T00:00:00","status":400,"error":"Bad Request","message":"Date range not allowed","path":"/api"}',
    'message only' => '{"message":"Date range not allowed"}',
    'plain text' => 'Date range not allowed',
]);

it('reads an empty 500 body without failing', function () {
    try {
        ResponseClassifier::decode(Responses::empty(500), ENDPOINT);
    } catch (ServiceUnavailableException $e) {
        expect($e->httpStatus)->toBe(500)->and($e->detail)->toBe('');

        return;
    }

    throw new LogicException('Expected an exception.');
});

it('redacts identifiers Datadis echoes in error bodies', function () {
    $body = 'Invalid cups ES0031300000000001JN0F for authorizedNif 12345678Z';

    try {
        ResponseClassifier::decode(Responses::text($body, 400), ENDPOINT);
    } catch (RequestRejectedException $e) {
        expect($e->getMessage())->not->toContain('ES0031300000000001JN0F')->not->toContain('12345678Z')
            ->and($e->detail)->not->toContain('ES0031300000000001JN0F')->not->toContain('12345678Z');

        return;
    }

    throw new LogicException('Expected an exception.');
});

it('caps the detail excerpt', function () {
    try {
        ResponseClassifier::decode(Responses::text(str_repeat('x', 5000), 500), ENDPOINT);
    } catch (ServiceUnavailableException $e) {
        expect(mb_strlen($e->detail))->toBeLessThanOrEqual(300);

        return;
    }

    throw new LogicException('Expected an exception.');
});
