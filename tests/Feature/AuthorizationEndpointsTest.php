<?php

declare(strict_types=1);

use Lenorix\DatadisClient\ApiVersion;
use Lenorix\DatadisClient\Exceptions\AuthenticationException;
use Lenorix\DatadisClient\Exceptions\InvalidRequestException;
use Lenorix\DatadisClient\Exceptions\RequestRejectedException;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Lenorix\DatadisClient\Tests\Support\Scenario;
use Lenorix\DatadisClient\Tests\Support\Tokens;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\Nif;

it('grants an authorization to a third party for every supply', function () {
    $s = Scenario::make();
    $s->http->queue(Responses::empty(200));

    $s->client->newAuthorization(Nif::fromString('00000000t'));

    expect($s->http->requests()[1]->getMethod())->toBe('GET')
        ->and($s->http->requests()[1]->getUri()->getPath())->toBe('/api-private/api/new-authorization')
        ->and($s->http->requests()[1]->getUri()->getQuery())->toBe('authorizedNif=00000000T');
});

it('grants an authorization for some supplies and a period', function () {
    $s = Scenario::make();
    $s->http->queue(Responses::text('OK'));

    $s->client->newAuthorization(
        Nif::fromString('00000000T'),
        new DateTimeImmutable('2026-10-01'),
        new DateTimeImmutable('2027-09-30'),
        Cups::fromString('ES0000000000000000AA0A'),
        Cups::fromString(Scenario::otherCups()),
    );

    expect($s->http->requests()[1]->getUri()->getQuery())
        ->toBe('authorizedNif=00000000T&startDate=2026%2F10%2F01&endDate=2027%2F09%2F30&cups=ES0000000000000000AA0A&cups='.Scenario::otherCups());
});

it('uses the v1 path whatever the configured version', function (ApiVersion $version) {
    $s = Scenario::make($version);
    $s->http->queue(Responses::empty(200));

    $s->client->cancelAuthorization(Nif::fromString('00000000T'), Cups::fromString('ES0000000000000000AA0A'));

    expect($s->http->requests()[1]->getUri()->getPath())->toBe('/api-private/api/cancel-authorization')
        ->and($s->http->requests()[1]->getUri()->getQuery())->toBe('authorizedNif=00000000T&cups=ES0000000000000000AA0A');
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
    'own account' => [fn ($c) => $c->newAuthorization(Nif::fromString('A00000000'))],
    'cancel own account' => [fn ($c) => $c->cancelAuthorization(Nif::fromString(' a00000000 '))],
    'end before start' => [fn ($c) => $c->newAuthorization(Nif::fromString('00000000T'), new DateTimeImmutable('2027-01-01'), new DateTimeImmutable('2026-01-01'))],
    'same cups twice' => [fn ($c) => $c->newAuthorization(Nif::fromString('00000000T'), null, null, Cups::fromString('ES0000000000000000AA0A'), Cups::fromString('es0000000000000000aa0a'))],
]);

it('reports a refused authorization as a typed failure', function () {
    $s = Scenario::make();
    $s->http->queue(Responses::text('Invalid NIF 00000000T', 400));

    $s->client->newAuthorization(Nif::fromString('00000000T'));
})->throws(RequestRejectedException::class);

it('lists the authorizations of the account', function () {
    $s = Scenario::make();
    $s->http->queue(Responses::json(datadisFixture('v1/list-authorization.json')));

    $result = $s->client->listAuthorization();

    expect($s->http->requests()[1]->getUri()->getPath())->toBe('/api-private/api/list-authorization')
        ->and($s->http->requests()[1]->getUri()->getQuery())->toBe('')
        ->and($result->records)->toHaveCount(2)
        ->and($result->records[0]->requesterDocument)->toBe('A00000000');
});

it('lists the authorizations of another owner', function () {
    $s = Scenario::make();
    $s->http->queue(Responses::datadis('[]'));

    expect($s->client->listAuthorization(Nif::fromString('00000000T'))->isEmpty())->toBeTrue()
        ->and($s->http->requests()[1]->getUri()->getQuery())->toBe('ownerNif=00000000T');
});

it('never sends a call that changes data again after a rejected token, and the next call logs in again', function (Closure $call, string $path) {
    $s = Scenario::make();
    $s->http->queue(
        Responses::datadisError(datadisFixture('errors/401-spring.json'), 401),
        Responses::text(Tokens::datadis(time())),
        Responses::datadis('{"supplies":[],"distributorError":[]}'),
    );

    try {
        $call($s->client);
    } catch (AuthenticationException $e) {
        $s->client->getSupplies();

        expect($e->requestSent)->toBeTrue()
            ->and(array_map(fn ($r) => $r->getUri()->getPath(), $s->http->requests()))
            ->toBe(['/nikola-auth/tokens/login', "/api-private/api/{$path}", '/nikola-auth/tokens/login', '/api-private/api/get-supplies-v2']);

        return;
    }

    throw new LogicException('Expected an AuthenticationException.');
})->with([
    'new authorization' => [fn ($c) => $c->newAuthorization(Nif::fromString('00000000T')), 'new-authorization'],
    'cancel authorization' => [fn ($c) => $c->cancelAuthorization(Nif::fromString('00000000T'), Cups::fromString(Scenario::CUPS)), 'cancel-authorization'],
    'unlink a partner user' => [fn ($c) => $c->partnerDeleteUser(Nif::fromString('00000000T')), 'partner-delete-user'],
]);

it('still logs in again and repeats a read that is safe to repeat after a rejected token', function () {
    $s = Scenario::make();
    $s->http->queue(
        Responses::datadisError(datadisFixture('errors/401-spring.json'), 401),
        Responses::text(Tokens::datadis(time())),
        Responses::datadis('{"authorizations":[]}'),
    );

    expect($s->client->listAuthorization()->isEmpty())->toBeTrue()->and($s->http->requests())->toHaveCount(4);
});
