<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\HttpFactory;
use Lenorix\DatadisClient\ConnectionSettings;
use Lenorix\DatadisClient\Exceptions\NoDataException;
use Lenorix\DatadisClient\Exceptions\RequestRejectedException;
use Lenorix\DatadisClient\Exceptions\UninterpretableResponseException;
use Lenorix\DatadisClient\PublicApi\Community;
use Lenorix\DatadisClient\PublicApi\PublicApi;
use Lenorix\DatadisClient\PublicApi\PublicSearchQuery;
use Lenorix\DatadisClient\PublicApi\SelfConsumptionSearchQuery;
use Lenorix\DatadisClient\Tests\Support\FakeHttpClient;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;

function publicApi(FakeHttpClient $http): PublicApi
{
    return new PublicApi(new ConnectionSettings(baseUrl: 'https://datadis.test'), $http);
}

function searchQuery(): PublicSearchQuery
{
    return new PublicSearchQuery(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'), [Community::Madrid], ['05']);
}

function autoQuery(): SelfConsumptionSearchQuery
{
    return new SelfConsumptionSearchQuery(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'), [Community::Madrid]);
}

it('calls each public endpoint without logging in', function (string $method, string $path, Closure $query) {
    $http = (new FakeHttpClient)->queue(Responses::json(datadisFixture('public/search.json')));

    $result = publicApi($http)->{$method}($query());

    expect($http->requests())->toHaveCount(1)
        ->and($http->lastRequest()->getUri()->getPath())->toBe($path)
        ->and($http->lastRequest()->hasHeader('Authorization'))->toBeFalse()
        ->and($result->records)->toHaveCount(1)
        ->and($result->records[0]->decimal('sumEnergy'))->toBe('1234.567');
})->with([
    ['search', '/api-public/api-search', fn () => searchQuery()],
    ['sumSearch', '/api-public/api-sum-search', fn () => searchQuery()],
    ['searchSelfConsumption', '/api-public/api-search-auto', fn () => autoQuery()],
    ['sumSearchSelfConsumption', '/api-public/api-sum-search-auto', fn () => autoQuery()],
]);

it('sends the query as built', function () {
    $http = (new FakeHttpClient)->queue(Responses::json('[]'));

    publicApi($http)->search(searchQuery());
    parse_str($http->lastRequest()->getUri()->getQuery(), $sent);

    expect($sent)->toBe(array_map('strval', searchQuery()->toQuery()));
});

it('reads a list wrapped in a common envelope key', function (string $body) {
    $http = (new FakeHttpClient)->queue(Responses::json($body));

    expect(publicApi($http)->search(searchQuery())->records)->toHaveCount(1);
})->with([
    'content' => '{"content":[{"sumEnergy":1}],"totalElements":1}',
    'data' => '{"data":[{"sumEnergy":1}]}',
    'single object' => '{"sumEnergy":1}',
]);

it('treats an empty page as the end of the results', function (string $body) {
    $http = (new FakeHttpClient)->queue(Responses::json($body));

    expect(publicApi($http)->search(searchQuery())->isEmpty())->toBeTrue();
})->with(['[]', '{"content":[]}']);

it('treats a no content answer as an empty page', function () {
    $http = (new FakeHttpClient)->queue(Responses::empty(204));

    expect(publicApi($http)->search(searchQuery())->isEmpty())->toBeTrue();
});

it('refuses answers it cannot read', function () {
    $http = (new FakeHttpClient)->queue(Responses::json('{"content":"x"}'));

    publicApi($http)->search(searchQuery());
})->throws(UninterpretableResponseException::class);

it('reports errors with the public endpoint name', function () {
    $http = (new FakeHttpClient)->queue(Responses::text('community is mandatory', 400));

    try {
        publicApi($http)->search(searchQuery());
    } catch (RequestRejectedException $e) {
        expect($e->endpoint)->toBe('api-search');

        return;
    }

    throw new LogicException('Expected a RequestRejectedException.');
});

it('walks every page until an empty or short page', function () {
    $page = fn (int $n) => json_encode(array_fill(0, $n, ['sumEnergy' => 1]));
    $http = (new FakeHttpClient)->queue(Responses::json($page(2)), Responses::json($page(2)), Responses::json($page(1)));
    $query = new PublicSearchQuery(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'), [Community::Madrid], ['05'], pageSize: 2);

    $records = iterator_to_array(publicApi($http)->searchAll($query), false);

    parse_str($http->requests()[2]->getUri()->getQuery(), $third);

    expect($records)->toHaveCount(5)->and($http->requests())->toHaveCount(3)->and($third['page'])->toBe('2');
});

it('stops walking at the page limit', function () {
    $http = (new FakeHttpClient)->queue(...array_fill(0, 3, Responses::json(json_encode([['a' => 1]]))));
    $query = new PublicSearchQuery(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'), [Community::Madrid], ['05'], pageSize: 1);

    $records = iterator_to_array(publicApi($http)->searchAll($query, maxPages: 3), false);

    expect($records)->toHaveCount(3)->and($http->requests())->toHaveCount(3);
});

it('builds with the default Guzzle transport and default settings', function () {
    expect(new PublicApi)->toBeInstanceOf(PublicApi::class);
});

it('yields unique keys across pages so iterator_to_array keeps every record', function () {
    $page = fn (int $n) => json_encode(array_fill(0, $n, ['sumEnergy' => 1]));
    $http = (new FakeHttpClient)->queue(Responses::json($page(2)), Responses::json($page(2)), Responses::json($page(1)));
    $query = new PublicSearchQuery(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'), [Community::Madrid], ['05'], pageSize: 2);

    expect(iterator_to_array(publicApi($http)->searchAll($query)))->toHaveCount(5);
});

it('walks every page of the self-consumption search', function () {
    $http = (new FakeHttpClient)->queue(Responses::json('[{"a":1},{"a":2}]'), Responses::json('[{"a":3}]'));
    $query = new SelfConsumptionSearchQuery(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'), [Community::Madrid], pageSize: 2);

    $records = iterator_to_array(publicApi($http)->searchSelfConsumptionAll($query));

    parse_str($http->requests()[1]->getUri()->getQuery(), $second);

    expect($records)->toHaveCount(3)->and($second['page'])->toBe('1')->and($http->lastRequest()->getUri()->getPath())->toBe('/api-public/api-search-auto');
});

it('reports a 404 of the public API as no data', function () {
    $http = (new FakeHttpClient)->queue(Responses::text('Not Found', 404));

    publicApi($http)->search(searchQuery());
})->throws(NoDataException::class);

it('numbers the records of all pages from zero', function () {
    $http = (new FakeHttpClient)->queue(Responses::json('[{"a":1},{"a":2}]'), Responses::json('[{"a":3}]'));
    $query = new PublicSearchQuery(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'), [Community::Madrid], ['05'], pageSize: 2);

    expect(array_keys(iterator_to_array(publicApi($http)->searchAll($query))))->toBe([0, 1, 2]);
});

it('counts an unusable row as part of a full page', function () {
    $http = (new FakeHttpClient)->queue(Responses::json('[{"a":1},5]'), Responses::json('[]'));
    $query = new PublicSearchQuery(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'), [Community::Madrid], ['05'], pageSize: 2);

    iterator_to_array(publicApi($http)->searchAll($query));

    expect($http->requests())->toHaveCount(2);
});

it('reports unusable rows', function () {
    $http = (new FakeHttpClient)->queue(Responses::json('[{"a":1},5,[]]'));

    expect(publicApi($http)->search(searchQuery())->skippedRows)->toBe(2);
});

it('reads an empty 200 as an empty page but fails on an envelope key that is not a list', function () {
    $http = (new FakeHttpClient)->queue(Responses::empty(200), Responses::json('{"content":{"a":1}}'));

    expect(publicApi($http)->search(searchQuery())->isEmpty())->toBeTrue()
        ->and(fn () => publicApi($http)->search(searchQuery()))->toThrow(UninterpretableResponseException::class);
});

it('builds public requests with the PSR-17 factories it is given', function () {
    $factory = new class implements RequestFactoryInterface
    {
        public int $calls = 0;

        public function createRequest(string $method, $uri): RequestInterface
        {
            $this->calls++;

            return (new HttpFactory)->createRequest($method, $uri);
        }
    };
    $http = (new FakeHttpClient)->queue(Responses::json('[]'));

    (new PublicApi(new ConnectionSettings(baseUrl: 'https://datadis.test'), $http, $factory))->search(searchQuery());

    expect($factory->calls)->toBe(1);
});

it('uses the public Datadis host by default', function () {
    $http = (new FakeHttpClient)->queue(Responses::json('[]'));

    (new PublicApi(http: $http))->search(searchQuery());

    expect($http->lastRequest()->getUri()->getHost())->toBe('datadis.es');
});

it('sends public requests to the configured host', function () {
    $http = (new FakeHttpClient)->queue(Responses::json('[]'));

    publicApi($http)->search(searchQuery());

    expect($http->lastRequest()->getUri()->getHost())->toBe('datadis.test');
});

it('refuses an empty object, which says nothing about the page', function () {
    publicApi((new FakeHttpClient)->queue(Responses::json('{}')))->search(searchQuery());
})->throws(UninterpretableResponseException::class);
