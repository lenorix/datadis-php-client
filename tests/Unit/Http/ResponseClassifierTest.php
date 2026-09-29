<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Lenorix\DatadisClient\Exceptions\AuthenticationException;
use Lenorix\DatadisClient\Exceptions\AuthorizationException;
use Lenorix\DatadisClient\Exceptions\DatadisException;
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

it('returns the text of a successful answer, empty or not', function () {
    expect(ResponseClassifier::assertSuccessful(Responses::empty(200), ENDPOINT))->toBe('')
        ->and(ResponseClassifier::assertSuccessful(Responses::empty(204), ENDPOINT))->toBe('')
        ->and(ResponseClassifier::assertSuccessful(Responses::text('OK'), ENDPOINT))->toBe('OK');
});

it('fails an unsuccessful answer the same way decode does', function () {
    ResponseClassifier::assertSuccessful(Responses::text('no', 403), ENDPOINT);
})->throws(AuthorizationException::class);

it('reads a body that is not UTF-8 as Windows-1252 instead of failing it', function () {
    $decoded = ResponseClassifier::decode(Responses::json("[{\"distributor\":\"EDISTRIBUCI\xD3N\"}]"), ENDPOINT);

    expect($decoded[0]['distributor'])->toBe('EDISTRIBUCIÓN');
});

it('leaves a valid UTF-8 body untouched', function () {
    expect(ResponseClassifier::decode(Responses::json('[{"distributor":"EDISTRIBUCIÓN"}]'), ENDPOINT)[0]['distributor'])->toBe('EDISTRIBUCIÓN');
});

it('draws the success range exactly between 200 and 299', function (int $status, bool $success) {
    $call = fn () => ResponseClassifier::decode(Responses::json('[1]', $status), ENDPOINT);

    $success ? expect($call())->toBe([1]) : expect($call)->toThrow(DatadisException::class);
})->with([[199, false], [200, true], [299, true], [300, false]]);

it('maps the edges of the error ranges', function (int $status, string $class) {
    expect(fn () => ResponseClassifier::decode(Responses::text('x', $status), ENDPOINT))->toThrow($class);
})->with([
    [399, UninterpretableResponseException::class],
    [400, RequestRejectedException::class],
    [499, RequestRejectedException::class],
    [500, ServiceUnavailableException::class],
    [599, ServiceUnavailableException::class],
]);

it('treats a 204 as no data even if it carries a body', function () {
    ResponseClassifier::decode(Responses::text('ignored', 204), ENDPOINT);
})->throws(NoDataException::class);

it('writes the message with and without a detail', function () {
    expect(fn () => ResponseClassifier::decode(Responses::empty(500), ENDPOINT))->toThrow(ServiceUnavailableException::class, ENDPOINT.': Datadis answered HTTP 500.')
        ->and(fn () => ResponseClassifier::decode(Responses::text('boom', 500), ENDPOINT))->toThrow(ServiceUnavailableException::class, ENDPOINT.': Datadis answered HTTP 500 · boom');
});

it('extracts the message of a JSON error body and keeps anything else as text', function (string $body, string $detail) {
    try {
        ResponseClassifier::decode(Responses::text($body, 400), ENDPOINT);
    } catch (RequestRejectedException $e) {
        expect($e->detail)->toBe($detail);

        return;
    }

    throw new LogicException('Expected an exception.');
})->with([
    'message' => ['{"message":"Date range not allowed"}', 'Date range not allowed'],
    'message with spaces around' => ["  {\"message\":\"m\"}\n", 'm'],
    'message is not text' => ['{"message":5}', '{"message":5}'],
    'no message' => ['{"error":"x"}', '{"error":"x"}'],
    'broken JSON' => ['{"message":', '{"message":'],
    'a list' => ['["message"]', '["message"]'],
]);

it('leaves no PHP error behind when a gzip-looking body is not gzip', function () {
    error_clear_last();

    try {
        ResponseClassifier::decode(Responses::json("\x1f\x8bnot really gzip"), ENDPOINT);
    } catch (UninterpretableResponseException) {
    }

    expect(error_get_last())->toBeNull();
});
