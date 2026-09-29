<?php

declare(strict_types=1);

use Lenorix\DatadisClient\Data\DistributorError;
use Lenorix\DatadisClient\Data\Envelope;
use Lenorix\DatadisClient\Exceptions\UninterpretableResponseException;

$decoder = fn (array $row): ?array => isset($row['ok']) ? $row : null;

it('reads a v2 envelope and its distributor errors', function () use ($decoder) {
    $result = Envelope::build([
        'timeCurve' => [['ok' => 1], ['ok' => 2]],
        'distributorError' => [['distributorCode' => '2', 'distributorName' => 'X', 'errorCode' => '50', 'errorDescription' => 'boom']],
    ], 'timeCurve', 'endpoint', $decoder);

    expect($result->records)->toBe([['ok' => 1], ['ok' => 2]])
        ->and($result->distributorErrors)->toHaveCount(1)
        ->and($result->distributorErrors[0])->toBeInstanceOf(DistributorError::class)
        ->and($result->distributorErrors[0]->errorDescription)->toBe('boom')
        ->and($result->skippedRows)->toBe(0);
});

it('reads a v1 bare list', function () use ($decoder) {
    $result = Envelope::build([['ok' => 1]], 'timeCurve', 'endpoint', $decoder);

    expect($result->records)->toBe([['ok' => 1]])->and($result->distributorErrors)->toBe([]);
});

it('treats an empty list, an empty envelope and a null list as an empty success', function (array $decoded) use ($decoder) {
    $result = Envelope::build($decoded, 'timeCurve', 'endpoint', $decoder);

    expect($result->isEmpty())->toBeTrue()->and($result->skippedRows)->toBe(0);
})->with([
    'empty list' => [[]],
    'empty list in envelope' => [['timeCurve' => [], 'distributorError' => []]],
    'null list' => [['timeCurve' => null, 'distributorError' => []]],
    'only errors key' => [['distributorError' => []]],
]);

it('tells "empty because a distributor failed" apart from plain empty', function () use ($decoder) {
    $failed = Envelope::build(json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/v2/distributor-error-only.json'), true), 'timeCurve', 'endpoint', $decoder);
    $plain = Envelope::build(['timeCurve' => [], 'distributorError' => []], 'timeCurve', 'endpoint', $decoder);

    expect($failed->isEmptyBecauseOfErrors())->toBeTrue()
        ->and($failed->distributorErrors[0]->errorDescription)->toBe('Error interno distribuidora')
        ->and($plain->isEmptyBecauseOfErrors())->toBeFalse();
});

it('keeps data that arrives together with distributor errors', function () use ($decoder) {
    $result = Envelope::build(['timeCurve' => [['ok' => 1]], 'distributorError' => [['errorCode' => '1']]], 'timeCurve', 'endpoint', $decoder);

    expect($result->records)->toHaveCount(1)->and($result->hasDistributorErrors())->toBeTrue()->and($result->isEmptyBecauseOfErrors())->toBeFalse();
});

it('skips unusable rows and counts them', function () use ($decoder) {
    $result = Envelope::build(['timeCurve' => [['ok' => 1], ['nope' => 1], 'text', null, 5]], 'timeCurve', 'endpoint', $decoder);

    expect($result->records)->toHaveCount(1)->and($result->skippedRows)->toBe(4);
});

it('fails when every row of a non-empty response is unusable', function () use ($decoder) {
    Envelope::build(['timeCurve' => [['nope' => 1], ['nope' => 2]]], 'timeCurve', 'endpoint', $decoder);
})->throws(UninterpretableResponseException::class);

it('fails on shapes that are not an envelope', function (array $decoded) use ($decoder) {
    Envelope::build($decoded, 'timeCurve', 'endpoint', $decoder);
})->with([
    'unrelated object' => [['message' => 'hello']],
    'list key is a scalar' => [['timeCurve' => 'x']],
    'list key is an object' => [['timeCurve' => ['a' => 1]]],
])->throws(UninterpretableResponseException::class);

it('ignores malformed distributor errors instead of failing', function () use ($decoder) {
    $result = Envelope::build(['timeCurve' => [], 'distributorError' => ['text', null, ['errorCode' => '9']]], 'timeCurve', 'endpoint', $decoder);

    expect($result->distributorErrors)->toHaveCount(1)->and($result->distributorErrors[0]->errorCode)->toBe('9');
});

it('wraps an unexpected throwable from a row decoder at the boundary', function () {
    Envelope::build(['timeCurve' => [['a' => 1]]], 'timeCurve', 'endpoint', fn (array $row) => throw new TypeError('boom'));
})->throws(UninterpretableResponseException::class);

it('reports the endpoint in the failure', function () use ($decoder) {
    try {
        Envelope::build(['message' => 'x'], 'timeCurve', 'get-consumption-data-v2', $decoder);
    } catch (UninterpretableResponseException $e) {
        expect($e->endpoint)->toBe('get-consumption-data-v2');

        return;
    }

    throw new LogicException('Expected an exception.');
});
