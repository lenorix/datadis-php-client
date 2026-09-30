<?php

declare(strict_types=1);

use Lenorix\DatadisClient\ApiVersion;
use Lenorix\DatadisClient\Exceptions\AuthenticationException;
use Lenorix\DatadisClient\Exceptions\AuthorizationException;
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
 * Every answer here was captured from the live API (September 2026): status, content type and
 * body, with nothing personal in them.
 */

$consumption = fn ($c) => $c->consumption(Cups::fromString(Scenario::CUPS), '2', 5, Month::of(2026, 6), Month::of(2026, 6));

it('classifies the real error answers of Datadis', function (int $status, string $contentType, string $body, string $class) use ($consumption) {
    $s = Scenario::make();
    $s->http->queue(Responses::text($body, $status, ['Content-Type' => $contentType]));

    try {
        $consumption($s->client);
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf($class)->and($e->httpStatus)->toBe($status)->and($e->requestSent)->toBeTrue();

        return;
    }

    throw new LogicException('Expected an exception.');
})->with([
    'no or altered token' => [401, 'application/json', datadisFixture('errors/401-spring.json'), AuthenticationException::class],
    'missing Accept header' => [400, 'text/plain;charset=UTF-8', 'Parámetro en cabecera requerido en estado vacío, con formato erróneo o con valores fuera de rango', RequestRejectedException::class],
    'authorizedNif without consent' => [400, 'application/json;charset=UTF-8', 'No se encuentra autorizado el cups introducido', AuthorizationException::class],
    'wrong distributor code' => [400, 'application/json;charset=UTF-8', 'Parámetro requerido en estado vacío, con formato erróneo, o con valores fuera de rango / Parámetro de ordenación erróneo', RequestRejectedException::class],
    'dates out of the window' => [400, 'application/json;charset=UTF-8', 'Fechas incorrectas revise: Formato de fechas YYYY/MM, las fechas deben ser anteriores o iguales al mes actual, fecha inicio no superior a fecha fin. La fecha inicio no puede ser superior a dos años.', RequestRejectedException::class],
    'wrong point type' => [400, 'application/json;charset=UTF-8', 'PointType incorrecto ', RequestRejectedException::class],
    'missing required parameter' => [500, 'application/json', '', ServiceUnavailableException::class],
]);

it('keeps the 401 retry: an altered token is refused, a new login is made and the call repeated', function () use ($consumption) {
    $s = Scenario::make();
    $s->http->queue(
        Responses::json(datadisFixture('errors/401-spring.json'), 401),
        Responses::text(Tokens::jwt(['exp' => time() + 86400])),
        Responses::text('[]', 200, ['Content-Type' => 'text/plain']),
    );

    expect($consumption($s->client)->isEmpty())->toBeTrue()->and($s->http->requests())->toHaveCount(4);
});

it('reads a JSON answer sent as text/plain', function () use ($consumption) {
    $s = Scenario::make();
    $s->http->queue(Responses::text('[]', 200, ['Content-Type' => 'text/plain']));

    expect($consumption($s->client)->isEmpty())->toBeTrue();
});

it('reads "no supplies" as an empty list, not as a failure', function (string $body) {
    $s = Scenario::make(ApiVersion::V1);
    $s->http->queue(Responses::text($body, 404, ['Content-Type' => 'application/json;charset=UTF-8']));

    $result = $s->client->supplies();

    expect($result->isEmpty())->toBeTrue();
})->with(['No supplies']);

it('finds no supply when the account has none', function () {
    $s = Scenario::make(ApiVersion::V1);
    $s->http->queue(Responses::text('No supplies', 404, ['Content-Type' => 'application/json;charset=UTF-8']));

    expect($s->client->findSupply(Cups::fromString(Scenario::CUPS)))->toBeNull();
});

it('still reports an authorizedNif without authorized supplies as an authorization failure', function () {
    $s = Scenario::make(ApiVersion::V1);
    $s->http->queue(Responses::text('No authorized supplies', 403, ['Content-Type' => 'application/json;charset=UTF-8']));

    $s->client->supplies(Nif::fromString('87654321X'));
})->throws(AuthorizationException::class);

it('reads the all-blank contract row Datadis sends for a CUPS it cannot see as no contract', function () {
    $s = Scenario::make(ApiVersion::V1);
    $s->http->queue(Responses::text(datadisFixture('v1/contract-detail-blank.json'), 200, ['Content-Type' => 'text/plain']));

    $result = $s->client->contractDetail(Cups::fromString(Scenario::CUPS), '2');

    expect($result->isEmpty())->toBeTrue()->and($result->skippedRows)->toBe(1);
});

it('still reports other no-data answers of supplies as no data', function () {
    $s = Scenario::make(ApiVersion::V1);
    $s->http->queue(Responses::empty(200));

    $s->client->supplies();
})->throws(NoDataException::class);
