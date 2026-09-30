<?php

declare(strict_types=1);

use Lenorix\DatadisClient\ApiVersion;
use Lenorix\DatadisClient\Exceptions\AuthenticationException;
use Lenorix\DatadisClient\Exceptions\AuthorizationException;
use Lenorix\DatadisClient\Exceptions\DatadisException;
use Lenorix\DatadisClient\Exceptions\NoDataException;
use Lenorix\DatadisClient\Exceptions\RequestRejectedException;
use Lenorix\DatadisClient\Exceptions\ServiceUnavailableException;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Lenorix\DatadisClient\Tests\Support\Scenario;
use Lenorix\DatadisClient\Tests\Support\Tokens;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\Nif;

/*
 * Every answer here was captured from the live API (September 2026, v1 paths): status, content
 * type and body, with nothing personal in them. They run on v1, as captured; the v2 cases at the
 * end assume v2 answers the same way, which is not verified.
 */

$consumption = fn ($c) => $c->consumption(Cups::fromString(Scenario::CUPS), '2', 5, Month::of(2026, 6), Month::of(2026, 6));

/** Asserts the failure and that exactly one data request was made (no hidden retry or re-login). */
function expectRealFailure(Scenario $s, Closure $call, string $class, int $status): DatadisException
{
    try {
        $call($s->client);
    } catch (DatadisException $e) {
        expect($e)->toBeInstanceOf($class)
            ->and($e->httpStatus)->toBe($status)
            ->and($e->requestSent)->toBeTrue()
            ->and($s->http->requests())->toHaveCount(2)
            ->and($s->http->pending())->toBe(0);

        return $e;
    }

    throw new LogicException("Expected a {$class}.");
}

it('classifies the real refusals of a data call', function (int $status, string $body, string $class) use ($consumption) {
    $s = Scenario::make(ApiVersion::V1);
    $s->http->queue(Responses::datadisError($body, $status));

    $e = expectRealFailure($s, $consumption, $class, $status);

    expect($e->detail)->toBe(trim($body));
})->with([
    'authorizedNif without consent, or a CUPS not written as Datadis has it' => [400, 'No se encuentra autorizado el cups introducido', AuthorizationException::class],
    'unknown distributor code' => [400, 'Parámetro requerido en estado vacío, con formato erróneo, o con valores fuera de rango / Parámetro de ordenación erróneo', RequestRejectedException::class],
    'unknown distributor code on consumption' => [400, 'CUPS o distributor no válido ', RequestRejectedException::class],
    'dates out of the window' => [400, 'Fechas incorrectas revise: Formato de fechas YYYY/MM, las fechas deben ser anteriores o iguales al mes actual, fecha inicio no superior a fecha fin. La fecha inicio no puede ser superior a dos años.', RequestRejectedException::class],
    'wrong point type' => [400, 'PointType incorrecto ', RequestRejectedException::class],
    'wrong measurement type' => [400, 'MeasurementType incorrecto ', RequestRejectedException::class],
]);

it('reads the missing Accept header answer, labelled text/plain, as a rejected request', function () use ($consumption) {
    $s = Scenario::make(ApiVersion::V1);
    $s->http->queue(Responses::text('Parámetro en cabecera requerido en estado vacío, con formato erróneo o con valores fuera de rango', 400));

    expectRealFailure($s, $consumption, RequestRejectedException::class, 400);
});

it('reads the empty 500 of a missing parameter as a failure of the service, not retried', function () use ($consumption) {
    $s = Scenario::make(ApiVersion::V1);
    $s->http->queue(Responses::empty(500));

    expect(expectRealFailure($s, $consumption, ServiceUnavailableException::class, 500)->detail)->toBe('');
});

it('reads an unknown path as a refusal without logging in again', function () use ($consumption) {
    $s = Scenario::make(ApiVersion::V1);
    $s->http->queue(Responses::text("403 Forbidden\n", 403, ['Content-Type' => 'text/plain']));

    expectRealFailure($s, $consumption, AuthorizationException::class, 403);
});

it('logs in once more after the real 401 and gives up on a second one', function () use ($consumption) {
    $s = Scenario::make(ApiVersion::V1);
    $s->http->queue(
        Responses::json(datadisFixture('errors/401-spring.json'), 401),
        Responses::text(Tokens::datadis(time())),
        Responses::json(datadisFixture('errors/401-spring.json'), 401),
    );

    try {
        $consumption($s->client);
    } catch (AuthenticationException $e) {
        expect($e->httpStatus)->toBe(401)
            ->and($e->getPrevious())->toBeNull()
            ->and($e->detail)->toBe('No message available')
            ->and($s->http->requests())->toHaveCount(4)
            ->and($s->http->requests()[2]->getUri()->getPath())->toBe('/nikola-auth/tokens/login');

        return;
    }

    throw new LogicException('Expected an AuthenticationException.');
});

it('logs in once more after the real 401 and carries on when the new token works', function () use ($consumption) {
    $s = Scenario::make(ApiVersion::V1);
    $s->http->queue(
        Responses::json(datadisFixture('errors/401-spring.json'), 401),
        Responses::text(Tokens::datadis(time())),
        Responses::datadis('[]'),
    );

    expect($consumption($s->client)->isEmpty())->toBeTrue()->and($s->http->requests())->toHaveCount(4);
});

it('reads "No supplies" as an empty list and finds no supply in it', function () {
    $s = Scenario::make(ApiVersion::V1);
    $s->http->queue(Responses::datadisError('No supplies', 404), Responses::datadisError('No supplies', 404));

    expect($s->client->supplies()->isEmpty())->toBeTrue()
        ->and($s->client->findSupply(Cups::fromString(Scenario::CUPS)))->toBeNull()
        ->and($s->http->requests()[1]->getUri()->getPath())->toBe('/api-private/api/get-supplies');
});

it('still reports other no-data answers of supplies as no data', function () {
    $s = Scenario::make(ApiVersion::V1);
    $s->http->queue(Responses::empty(200));

    $s->client->supplies();
})->throws(NoDataException::class);

it('reports an authorizedNif that authorized nothing as an authorization failure', function () {
    $s = Scenario::make(ApiVersion::V1);
    $s->http->queue(Responses::datadisError('No authorized supplies', 403));

    expectRealFailure($s, fn ($c) => $c->supplies(Nif::fromString('87654321X')), AuthorizationException::class, 403);
});

it('reads the all-blank contract row Datadis sends for a CUPS it cannot see as no contract', function () {
    $s = Scenario::make(ApiVersion::V1);
    $s->http->queue(Responses::datadis(datadisFixture('v1/contract-detail-blank.json')));

    $result = $s->client->contractDetail(Cups::fromString(Scenario::CUPS), '2');

    expect($result->isEmpty())->toBeTrue()
        ->and($result->skippedRows)->toBe(1)
        ->and($s->http->requests()[1]->getUri()->getPath())->toBe('/api-private/api/get-contract-detail');
});

it('assumes v2 refuses a missing consent the same way (unverified: captured on v1)', function () use ($consumption) {
    $s = Scenario::make(ApiVersion::V2);
    $s->http->queue(Responses::datadisError('No se encuentra autorizado el cups introducido', 400));

    expectRealFailure($s, $consumption, AuthorizationException::class, 400);
});

it('assumes v2 sends the blank contract row inside its envelope (unverified: captured on v1)', function () {
    $s = Scenario::make(ApiVersion::V2);
    $blank = json_decode(datadisFixture('v1/contract-detail-blank.json'), true);
    $s->http->queue(Responses::datadis(['contract' => $blank, 'distributorError' => []]));

    expect($s->client->contractDetail(Cups::fromString(Scenario::CUPS), '2')->isEmpty())->toBeTrue();
});
