<?php

declare(strict_types=1);

use Lenorix\DatadisClient\ApiVersion;
use Lenorix\DatadisClient\ConnectionSettings;
use Lenorix\DatadisClient\Data\ApiResult;
use Lenorix\DatadisClient\Data\Supply;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Exceptions\DatadisException;
use Lenorix\DatadisClient\Exceptions\ServiceUnavailableException;
use Lenorix\DatadisClient\Exceptions\TransportException;
use Lenorix\DatadisClient\Exceptions\UninterpretableResponseException;
use Lenorix\DatadisClient\PublicApi\Community;
use Lenorix\DatadisClient\PublicApi\PublicSearchQuery;
use Lenorix\DatadisClient\PublicApiClient;
use Lenorix\DatadisClient\Tests\Support\FakeHttpClient;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Lenorix\DatadisClient\Tests\Support\Scenario;
use Lenorix\DatadisClient\Values\Cups;

it('does not take a distributor failure for "this supply is not yours"', function () {
    $s = Scenario::make();
    $s->http->queue(Responses::datadis('{"supplies":[],"distributorError":['
        .'{"distributorCode":"2","distributorName":"EDISTRIBUCIÓN","errorCode":"50","errorDescription":"Error interno distribuidora"},'
        .'{"distributorCode":"8","errorCode":"50","errorDescription":"Titular 00000000  A sin respuesta"}]}'));

    try {
        $s->client->findSupply(Cups::fromString(Scenario::CUPS));
    } catch (ServiceUnavailableException $e) {
        expect($e->requestSent)->toBeTrue()
            ->and($e->httpStatus)->toBe(200)
            ->and($e->getMessage())->toContain('Error interno distribuidora')->toContain('sin respuesta')
            ->and($e->getMessage().$e->detail)->not->toMatch('/00000000/');

        return;
    }

    throw new LogicException('Expected a ServiceUnavailableException.');
});

it('still finds the supply when another distributor failed', function () {
    $s = Scenario::make();
    $body = json_decode(datadisFixture('v2/supplies.json'), true);
    $body['distributorError'] = [['distributorCode' => '8', 'errorDescription' => 'Error interno distribuidora']];
    $s->http->queue(Responses::datadis($body));

    expect($s->client->findSupply(Cups::fromString(Scenario::CUPS))?->cups)->toBe(Scenario::CUPS);
});

it('keeps the password exactly as given in the settings', function () {
    expect(DatadisConfig::fromArray(['username' => 'A00000000', 'password' => '  secret with spaces  '])->password())->toBe('  secret with spaces  ');
});

it('iterates and counts the records of a result', function () {
    $result = new ApiResult(['a', 'b'], skippedRows: 3);

    expect(count($result))->toBe(2)
        ->and(iterator_to_array($result))->toBe(['a', 'b'])
        ->and($result)->toBeInstanceOf(Countable::class)->toBeInstanceOf(IteratorAggregate::class);
});

it('reads a null list of distributor codes as no codes', function (string $body) {
    $s = Scenario::make();
    $s->http->queue(Responses::datadis($body));

    expect($s->client->getDistributorsWithSupplies()->isEmpty())->toBeTrue();
})->with(['{"distExistenceUser":{"distributorCodes":null},"distributorError":[]}', '{"distributorCodes":null}', '{"distExistenceUser":null,"distributorError":[]}']);

it('fails instead of answering "no distributors" when not one code can be read', function (string $body) {
    $s = Scenario::make();
    $s->http->queue(Responses::datadis($body));

    expect(fn () => $s->client->getDistributorsWithSupplies())->toThrow(UninterpretableResponseException::class);
})->with(['{"distExistenceUser":{"distributorCodes":[null,"",{}]},"distributorError":[]}', '{"distributorCodes":[" "]}']);

it('fails instead of answering "no data" when not one public record can be read', function (string $body) {
    $http = (new FakeHttpClient)->queue(Responses::json($body));
    $api = new PublicApiClient(new ConnectionSettings(baseUrl: 'https://datadis.test'), $http);

    expect(fn () => $api->apiSearch(new PublicSearchQuery(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'), [Community::Madrid])))
        ->toThrow(UninterpretableResponseException::class);
})->with(['[null, 1, "x"]', '{"content":[null,[]]}']);

it('reads a 404 on the distributors list as an empty list, like the supplies list', function () {
    $s = Scenario::make(ApiVersion::V1);
    $s->http->queue(Responses::datadisError('No supplies', 404));

    expect($s->client->getDistributorsWithSupplies()->isEmpty())->toBeTrue();
});

it('keeps big numeric ids exact', function () {
    $s = Scenario::make();
    $s->http->queue(Responses::datadis('[{"id":12345678901234567890,"ownerDocument":"A00000000"}]'));

    expect($s->client->listAuthorization()->records[0]->id)->toBe('12345678901234567890');
});

it('is queryable only with a distributor code and a point type Datadis accepts', function (array $row, bool $queryable) {
    expect(Supply::fromRow(['cups' => Scenario::CUPS] + $row, new DateTimeZone('Europe/Madrid'))->isQueryable())->toBe($queryable);
})->with([
    [['distributorCode' => '2', 'pointType' => 5], true],
    [['distributorCode' => '2', 'pointType' => 1], true],
    [['distributorCode' => '2', 'pointType' => 7], false],
    [['distributorCode' => '2', 'pointType' => 0], false],
    [['distributorCode' => 'a b', 'pointType' => 5], false],
]);

it('keeps a transport failure apart from a service failure, since only the latter is safe to wait out', function () {
    expect(new TransportException('x'))->toBeInstanceOf(DatadisException::class)
        ->not->toBeInstanceOf(ServiceUnavailableException::class);
});
