<?php

declare(strict_types=1);

use Lenorix\DatadisClient\ApiVersion;
use Lenorix\DatadisClient\Exceptions\InvalidRequestException;
use Lenorix\DatadisClient\Exceptions\RequestRejectedException;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Lenorix\DatadisClient\Tests\Support\Scenario;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\Nif;

it('grants an authorization to a third party for every supply', function () {
    $s = Scenario::make();
    $s->http->queue(Responses::empty(200));

    $s->client->newAuthorization(Nif::fromString('87654321x'));

    expect($s->http->requests()[1]->getMethod())->toBe('GET')
        ->and($s->http->requests()[1]->getUri()->getPath())->toBe('/api-private/api/new-authorization')
        ->and($s->http->requests()[1]->getUri()->getQuery())->toBe('authorizedNif=87654321X');
});

it('grants an authorization for some supplies and a period', function () {
    $s = Scenario::make();
    $s->http->queue(Responses::text('OK'));

    $s->client->newAuthorization(
        Nif::fromString('87654321X'),
        new DateTimeImmutable('2026-10-01'),
        new DateTimeImmutable('2027-09-30'),
        Cups::fromString('ES0031300000000001JN0F'),
        Cups::fromString('ES0031300000000002JN'),
    );

    expect($s->http->requests()[1]->getUri()->getQuery())
        ->toBe('authorizedNif=87654321X&startDate=2026%2F10%2F01&endDate=2027%2F09%2F30&cups=ES0031300000000001JN0F&cups=ES0031300000000002JN');
});

it('uses the v1 path whatever the configured version', function (ApiVersion $version) {
    $s = Scenario::make($version);
    $s->http->queue(Responses::empty(200));

    $s->client->cancelAuthorization(Nif::fromString('87654321X'), Cups::fromString('ES0031300000000001JN0F'));

    expect($s->http->requests()[1]->getUri()->getPath())->toBe('/api-private/api/cancel-authorization')
        ->and($s->http->requests()[1]->getUri()->getQuery())->toBe('authorizedNif=87654321X&cups=ES0031300000000001JN0F');
})->with([ApiVersion::V1, ApiVersion::V2]);

it('refuses to authorize the account itself or an impossible period, before sending anything', function (Closure $call) {
    $s = Scenario::make();

    try {
        $call($s->client);
    } catch (InvalidRequestException $e) {
        expect($s->http->requests())->toBe([]);

        return;
    }

    throw new LogicException('Expected an InvalidRequestException.');
})->with([
    'own account' => [fn ($c) => $c->newAuthorization(Nif::fromString('12345678Z'))],
    'cancel own account' => [fn ($c) => $c->cancelAuthorization(Nif::fromString(' 12345678z '))],
    'end before start' => [fn ($c) => $c->newAuthorization(Nif::fromString('87654321X'), new DateTimeImmutable('2027-01-01'), new DateTimeImmutable('2026-01-01'))],
    'same cups twice' => [fn ($c) => $c->newAuthorization(Nif::fromString('87654321X'), null, null, Cups::fromString('ES0031300000000001JN0F'), Cups::fromString('es0031300000000001jn0f'))],
]);

it('reports a refused authorization as a typed failure', function () {
    $s = Scenario::make();
    $s->http->queue(Responses::text('Invalid NIF 87654321X', 400));

    $s->client->newAuthorization(Nif::fromString('87654321X'));
})->throws(RequestRejectedException::class);

it('lists the authorizations of the account', function () {
    $s = Scenario::make();
    $s->http->queue(Responses::json(datadisFixture('v1/list-authorization.json')));

    $result = $s->client->authorizations();

    expect($s->http->requests()[1]->getUri()->getPath())->toBe('/api-private/api/list-authorization')
        ->and($s->http->requests()[1]->getUri()->getQuery())->toBe('')
        ->and($result->records)->toHaveCount(2)
        ->and($result->records[0]->requesterDocument)->toBe('87654321X');
});

it('lists the authorizations of another owner', function () {
    $s = Scenario::make();
    $s->http->queue(Responses::json('[]'));

    expect($s->client->authorizations(Nif::fromString('87654321X'))->isEmpty())->toBeTrue()
        ->and($s->http->requests()[1]->getUri()->getQuery())->toBe('ownerNif=87654321X');
});
