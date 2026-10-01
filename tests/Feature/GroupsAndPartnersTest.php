<?php

declare(strict_types=1);

use Lenorix\DatadisClient\ApiVersion;
use Lenorix\DatadisClient\Exceptions\UnsupportedOperationException;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Lenorix\DatadisClient\Tests\Support\Scenario;
use Lenorix\DatadisClient\Values\Nif;

it('lists the groups of the account (the shape of a group is documented, not yet seen)', function (string $body) {
    $s = Scenario::make();
    $s->http->queue(Responses::datadis($body));

    $result = $s->client->getGroups();

    expect($s->http->requests()[1]->getUri()->getPath())->toBe('/api-private/api/get-groups-v2')
        ->and($s->http->requests()[1]->getUri()->getQuery())->toBe('')
        ->and($result->records)->toHaveCount(2)
        ->and($result->records[0]->name)->toBe('Oficinas');
})->with([
    'bare list' => [datadisFixture('v2/groups.json')],
    'envelope' => ['{"groups":'.datadisFixture('v2/groups.json').',"distributorError":[]}'],
]);

it('refuses groups in v1, where they do not exist', function () {
    $s = Scenario::make(ApiVersion::V1);

    expect(fn () => $s->client->getGroups())->toThrow(UnsupportedOperationException::class)
        ->and($s->http->requests())->toBe([]);
});

it('unlinks a user from the partner', function () {
    $s = Scenario::make();
    $s->http->queue(Responses::text('OK'));

    expect($s->client->partnerDeleteUser(Nif::fromString('00000000t')))->toBe('OK')
        ->and($s->http->requests()[1]->getUri()->getPath())->toBe('/api-private/api/partner-delete-user')
        ->and($s->http->requests()[1]->getUri()->getQuery())->toBe('nif=00000000T');
});

it('asks for the agreement date of the partner, optionally for a given NIF', function (?string $nif, string $query) {
    $s = Scenario::make();
    $s->http->queue(Responses::json('{"partnerAgreementDate": null}'));

    expect($s->client->partnerAgreementDate($nif === null ? null : Nif::fromString($nif)))->toBeNull()
        ->and($s->http->requests()[1]->getUri()->getPath())->toBe('/api-private/api/partner-agreement-date')
        ->and($s->http->requests()[1]->getUri()->getQuery())->toBe($query);
})->with([[null, ''], ['00000000T', 'nif=00000000T']]);
