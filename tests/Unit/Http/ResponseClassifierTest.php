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

const CLASSIFIED_ENDPOINT = 'get-consumption-data-v2';

it('decodes a JSON object', function () {
    $decoded = ResponseClassifier::decode(Responses::json('{"timeCurve":[{"a":1}],"distributorError":[]}'), CLASSIFIED_ENDPOINT);

    expect($decoded)->toBe(['timeCurve' => [['a' => 1]], 'distributorError' => []]);
});

it('decodes a JSON list, including an empty one', function () {
    expect(ResponseClassifier::decode(Responses::json('[]'), CLASSIFIED_ENDPOINT))->toBe([])
        ->and(ResponseClassifier::decode(Responses::json('[{"cups":"x"}]'), CLASSIFIED_ENDPOINT))->toBe([['cups' => 'x']]);
});

it('tolerates a byte order mark and surrounding whitespace', function () {
    expect(ResponseClassifier::decode(Responses::json("\xEF\xBB\xBF \n[1]\n"), CLASSIFIED_ENDPOINT))->toBe([1]);
});

it('inflates a gzip body that is not labelled as gzip', function () {
    expect(ResponseClassifier::decode(Responses::json((string) gzencode('{"a":1}')), CLASSIFIED_ENDPOINT))->toBe(['a' => 1]);
});

it('keeps a plain body whose bytes only look like a broken gzip header', function () {
    expect(fn () => ResponseClassifier::decode(Responses::json("\x1f\x8bnot really gzip"), CLASSIFIED_ENDPOINT))
        ->toThrow(UninterpretableResponseException::class);
});

it('maps empty bodies and 204 to no data', function (Response $response) {
    ResponseClassifier::decode($response, CLASSIFIED_ENDPOINT);
})->with([
    '200 empty' => fn () => Responses::empty(200),
    '200 blank' => fn () => Responses::text("  \n"),
    '204' => fn () => Responses::empty(204),
])->throws(NoDataException::class);

it('maps unusable 200 bodies to an uninterpretable response', function (Response $response) {
    ResponseClassifier::decode($response, CLASSIFIED_ENDPOINT);
})->with([
    'html maintenance page' => fn () => Responses::text('<html><body>Mantenimiento</body></html>', 200, ['Content-Type' => 'text/html']),
    'json string' => fn () => Responses::datadis('"maintenance"'),
    'json number' => fn () => Responses::datadis('42'),
    'json null' => fn () => Responses::datadis('null'),
    'json true' => fn () => Responses::datadis('true'),
    'truncated json' => fn () => Responses::datadis('{"timeCurve":['),
])->throws(UninterpretableResponseException::class);

it('maps each error status to its exception', function (Response $response, string $class) {
    $thrown = null;

    try {
        ResponseClassifier::decode($response, CLASSIFIED_ENDPOINT);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf($class)
        ->and($thrown->httpStatus)->toBe($response->getStatusCode())
        ->and($thrown->endpoint)->toBe(CLASSIFIED_ENDPOINT)
        ->and($thrown->requestSent)->toBeTrue();
})->with([
    'rejected parameters' => [fn () => Responses::datadisError('MeasurementType incorrecto ', 400), RequestRejectedException::class],
    'refused token' => [fn () => Responses::datadisError(datadisFixture('errors/401-spring.json'), 401), AuthenticationException::class],
    'nothing authorized' => [fn () => Responses::datadisError('No authorized supplies', 403), AuthorizationException::class],
    'unknown path' => [fn () => Responses::text('403 Forbidden', 403), AuthorizationException::class],
    'no supplies' => [fn () => Responses::datadisError('No supplies', 404), NoDataException::class],
    'repetition' => [fn () => Responses::datadisError('Consulta ya realizada', 429), RepetitionWindowException::class],
    'missing parameter' => [fn () => Responses::empty(500), ServiceUnavailableException::class],
    'gateway' => [fn () => Responses::text('<html>502 Bad Gateway</html>', 502, ['Content-Type' => 'text/html']), ServiceUnavailableException::class],
    'another 4xx' => [fn () => Responses::text('Conflict', 409), RequestRejectedException::class],
    'a redirect, which is never followed' => [fn () => Responses::empty(301), UninterpretableResponseException::class],
]);

it('reads the message of the error bodies Datadis sends', function (Response $response, string $detail) {
    try {
        ResponseClassifier::decode($response, CLASSIFIED_ENDPOINT);
    } catch (DatadisException $e) {
        expect($e->detail)->toContain($detail);

        return;
    }

    throw new LogicException('Expected an exception.');
})->with([
    'Spring JSON of a refused token' => [fn () => Responses::datadisError(datadisFixture('errors/401-spring.json'), 401), 'No message available'],
    'plain text labelled JSON' => [fn () => Responses::datadisError('Fechas incorrectas revise: Formato de fechas YYYY/MM', 400), 'Fechas incorrectas'],
    'plain text labelled text' => [fn () => Responses::text('Parámetro en cabecera requerido en estado vacío', 400), 'Parámetro en cabecera'],
]);

it('reads an empty 500 body without failing', function () {
    try {
        ResponseClassifier::decode(Responses::empty(500), CLASSIFIED_ENDPOINT);
    } catch (ServiceUnavailableException $e) {
        expect($e->httpStatus)->toBe(500)->and($e->detail)->toBe('');

        return;
    }

    throw new LogicException('Expected an exception.');
});

it('redacts identifiers Datadis echoes in error bodies', function () {
    $body = 'Invalid cups ES0000000000000000AA0A for authorizedNif A00000000';

    try {
        ResponseClassifier::decode(Responses::text($body, 400), CLASSIFIED_ENDPOINT);
    } catch (RequestRejectedException $e) {
        expect($e->getMessage())->not->toContain('ES0000000000000000AA0A')->not->toContain('A00000000')
            ->and($e->detail)->not->toContain('ES0000000000000000AA0A')->not->toContain('A00000000');

        return;
    }

    throw new LogicException('Expected an exception.');
});

it('caps the detail excerpt', function () {
    try {
        ResponseClassifier::decode(Responses::text(str_repeat('x', 5000), 500), CLASSIFIED_ENDPOINT);
    } catch (ServiceUnavailableException $e) {
        expect(mb_strlen($e->detail))->toBeLessThanOrEqual(300);

        return;
    }

    throw new LogicException('Expected an exception.');
});

it('returns the text of a successful answer, empty or not', function () {
    expect(ResponseClassifier::assertSuccessful(Responses::empty(200), CLASSIFIED_ENDPOINT))->toBe('')
        ->and(ResponseClassifier::assertSuccessful(Responses::empty(204), CLASSIFIED_ENDPOINT))->toBe('')
        ->and(ResponseClassifier::assertSuccessful(Responses::text('OK'), CLASSIFIED_ENDPOINT))->toBe('OK');
});

it('fails an unsuccessful answer the same way decode does', function () {
    ResponseClassifier::assertSuccessful(Responses::text('no', 403), CLASSIFIED_ENDPOINT);
})->throws(AuthorizationException::class);

it('reads a body that is not UTF-8 as Windows-1252 instead of failing it', function () {
    $decoded = ResponseClassifier::decode(Responses::json("[{\"distributor\":\"EDISTRIBUCI\xD3N\"}]"), CLASSIFIED_ENDPOINT);

    expect($decoded[0]['distributor'])->toBe('EDISTRIBUCIÓN');
});

it('leaves a valid UTF-8 body untouched', function () {
    expect(ResponseClassifier::decode(Responses::json('[{"distributor":"EDISTRIBUCIÓN"}]'), CLASSIFIED_ENDPOINT)[0]['distributor'])->toBe('EDISTRIBUCIÓN');
});

it('writes the message with and without a detail', function () {
    expect(fn () => ResponseClassifier::decode(Responses::empty(500), CLASSIFIED_ENDPOINT))->toThrow(ServiceUnavailableException::class, CLASSIFIED_ENDPOINT.': Datadis answered HTTP 500.')
        ->and(fn () => ResponseClassifier::decode(Responses::text('boom', 500), CLASSIFIED_ENDPOINT))->toThrow(ServiceUnavailableException::class, CLASSIFIED_ENDPOINT.': Datadis answered HTTP 500 · boom');
});

it('keeps only the message or the error of a JSON error body, and describes any other JSON', function (string $body, string $detail) {
    try {
        ResponseClassifier::decode(Responses::text($body, 400), CLASSIFIED_ENDPOINT);
    } catch (RequestRejectedException $e) {
        expect($e->detail)->toBe($detail);

        return;
    }

    throw new LogicException('Expected an exception.');
})->with([
    'message' => ['{"message":"Date range not allowed"}', 'Date range not allowed'],
    'message with spaces around' => ["  {\"message\":\"m\"}\n", 'm'],
    'message is not text' => ['{"message":5}', '[a JSON answer, not quoted]'],
    'no message, an error' => ['{"error":"x"}', 'x'],
    'neither' => ['{"ownerName":"JUAN PEREZ"}', '[a JSON answer, not quoted]'],
    'broken JSON' => ['{"message":', '[a JSON answer, not quoted]'],
    'a list' => ['["message"]', '[a JSON answer, not quoted]'],
    'an HTML page' => ['<html><body>JUAN PEREZ</body></html>', '[an HTML page, not quoted]'],
    'plain text' => ['Fechas incorrectas', 'Fechas incorrectas'],
]);

it('leaves no PHP error behind when a gzip-looking body is not gzip', function () {
    error_clear_last();

    try {
        ResponseClassifier::decode(Responses::json("\x1f\x8bnot really gzip"), CLASSIFIED_ENDPOINT);
    } catch (UninterpretableResponseException) {
    }

    expect(error_get_last())->toBeNull();
});

it('reads the real "not authorized" 400 as an authorization failure', function () {
    try {
        ResponseClassifier::decode(Responses::text('No se encuentra autorizado el cups introducido', 400), CLASSIFIED_ENDPOINT);
    } catch (AuthorizationException $e) {
        expect($e->httpStatus)->toBe(400);

        return;
    }

    throw new LogicException('Expected an AuthorizationException.');
});
