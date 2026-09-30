<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\HttpFactory;
use Lenorix\DatadisClient\ConnectionSettings;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Exceptions\NoDataException;
use Lenorix\DatadisClient\Exceptions\RequestRejectedException;
use Lenorix\DatadisClient\Exceptions\UninterpretableResponseException;
use Lenorix\DatadisClient\PublicApi\Community;
use Lenorix\DatadisClient\PublicApi\PublicSearchQuery;
use Lenorix\DatadisClient\PublicApi\SelfConsumptionSearchQuery;
use Lenorix\DatadisClient\PublicApiClient;
use Lenorix\DatadisClient\Tests\Support\FakeHttpClient;
use Lenorix\DatadisClient\Tests\Support\Responses;
use Lenorix\DatadisClient\Tests\Support\Tokens;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;

function publicApi(FakeHttpClient $http): PublicApiClient
{
    return new PublicApiClient(new ConnectionSettings(baseUrl: 'https://datadis.test'), $http);
}

function searchQuery(): PublicSearchQuery
{
    return new PublicSearchQuery(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'), [Community::Madrid], ['05']);
}

function autoQuery(): SelfConsumptionSearchQuery
{
    return new SelfConsumptionSearchQuery(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'), [Community::Madrid]);
}

it('calls each public endpoint without logging in and reads its answer', function (string $method, string $path, Closure $query, string $fixture, Closure $check) {
    $http = (new FakeHttpClient)->queue(Responses::json(datadisFixture($fixture)));

    $result = publicApi($http)->{$method}($query());

    expect($http->requests())->toHaveCount(1)
        ->and($http->lastRequest()->getUri()->getPath())->toBe($path)
        ->and($http->lastRequest()->hasHeader('Authorization'))->toBeFalse()
        ->and($result->records)->toHaveCount(1);

    $check($result->records[0]);
})->with([
    'search' => ['apiSearch', '/api-public/api-search', fn () => searchQuery(), 'public/search.json', fn ($r) => expect($r->sumEnergy())->toBe('30300495.000')],
    'sum' => ['apiSumSearch', '/api-public/api-sum-search', fn () => searchQuery(), 'public/sum-search.json', fn ($r) => expect($r->sumEnergy())->toBe('1093523120.000')->and($r->sumContracts())->toBe(5977431)],
    'self-consumption search' => ['apiSearchAuto', '/api-public/api-search-auto', fn () => autoQuery(), 'public/search-auto.json', fn ($r) => expect($r->sumPower())->toBe('121843.000')->and($r->sumContracts())->toBe(1609)],
    'self-consumption sum' => ['apiSumSearchAuto', '/api-public/api-sum-search-auto', fn () => autoQuery(), 'public/sum-search-auto.json', fn ($r) => expect($r->sumEnergy())->toBe('55304627.000')->and($r->sumPower())->toBe('3247427.000')->and($r->sumContracts())->toBe(16577)],
]);

it('sends the query as built', function () {
    $http = (new FakeHttpClient)->queue(Responses::json('[]'));

    publicApi($http)->apiSearch(searchQuery());
    parse_str($http->lastRequest()->getUri()->getQuery(), $sent);

    expect($sent)->toBe(array_map('strval', searchQuery()->toQuery()));
});

it('reads a list wrapped in a common envelope key', function (string $body) {
    $http = (new FakeHttpClient)->queue(Responses::json($body));

    expect(publicApi($http)->apiSearch(searchQuery())->records)->toHaveCount(1);
})->with([
    'content' => '{"content":[{"sumEnergy":1}],"totalElements":1}',
    'data' => '{"data":[{"sumEnergy":1}]}',
    'single object' => '{"sumEnergy":1}',
]);

it('treats an empty page as the end of the results', function (string $body) {
    $http = (new FakeHttpClient)->queue(Responses::json($body));

    expect(publicApi($http)->apiSearch(searchQuery())->isEmpty())->toBeTrue();
})->with(['[]', '{"content":[]}']);

it('treats a no content answer as an empty page', function () {
    $http = (new FakeHttpClient)->queue(Responses::empty(204));

    expect(publicApi($http)->apiSearch(searchQuery())->isEmpty())->toBeTrue();
});

it('refuses answers it cannot read', function () {
    $http = (new FakeHttpClient)->queue(Responses::json('{"content":"x"}'));

    publicApi($http)->apiSearch(searchQuery());
})->throws(UninterpretableResponseException::class);

it('reports errors with the public endpoint name', function () {
    $http = (new FakeHttpClient)->queue(Responses::text('community is mandatory', 400));

    try {
        publicApi($http)->apiSearch(searchQuery());
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

    $records = iterator_to_array(publicApi($http)->apiSearchAll($query), false);

    parse_str($http->requests()[2]->getUri()->getQuery(), $third);

    expect($records)->toHaveCount(5)->and($http->requests())->toHaveCount(3)->and($third['page'])->toBe('2');
});

it('stops walking at the page limit', function () {
    $http = (new FakeHttpClient)->queue(...array_fill(0, 3, Responses::json(json_encode([['a' => 1]]))));
    $query = new PublicSearchQuery(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'), [Community::Madrid], ['05'], pageSize: 1);

    $records = iterator_to_array(publicApi($http)->apiSearchAll($query, maxPages: 3), false);

    expect($records)->toHaveCount(3)->and($http->requests())->toHaveCount(3);
});

it('walks every page of the self-consumption search', function () {
    $http = (new FakeHttpClient)->queue(Responses::json('[{"a":1},{"a":2}]'), Responses::json('[{"a":3}]'));
    $query = new SelfConsumptionSearchQuery(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'), [Community::Madrid], pageSize: 2);

    $records = iterator_to_array(publicApi($http)->apiSearchAutoAll($query));

    parse_str($http->requests()[1]->getUri()->getQuery(), $second);

    expect($records)->toHaveCount(3)->and($second['page'])->toBe('1')->and($http->lastRequest()->getUri()->getPath())->toBe('/api-public/api-search-auto');
});

it('reports a 404 of the public API as no data', function () {
    $http = (new FakeHttpClient)->queue(Responses::text('Not Found', 404));

    publicApi($http)->apiSearch(searchQuery());
})->throws(NoDataException::class);

it('numbers the records of all pages from zero', function () {
    $http = (new FakeHttpClient)->queue(Responses::json('[{"a":1},{"a":2}]'), Responses::json('[{"a":3}]'));
    $query = new PublicSearchQuery(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'), [Community::Madrid], ['05'], pageSize: 2);

    expect(array_keys(iterator_to_array(publicApi($http)->apiSearchAll($query))))->toBe([0, 1, 2]);
});

it('counts an unusable row as part of a full page', function () {
    $http = (new FakeHttpClient)->queue(Responses::json('[{"a":1},5]'), Responses::json('[]'));
    $query = new PublicSearchQuery(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'), [Community::Madrid], ['05'], pageSize: 2);

    iterator_to_array(publicApi($http)->apiSearchAll($query));

    expect($http->requests())->toHaveCount(2);
});

it('reports unusable rows', function () {
    $http = (new FakeHttpClient)->queue(Responses::json('[{"a":1},5,[]]'));

    expect(publicApi($http)->apiSearch(searchQuery())->skippedRows)->toBe(2);
});

it('reads an empty 200 as an empty page but fails on an envelope key that is not a list', function () {
    $http = (new FakeHttpClient)->queue(Responses::empty(200), Responses::json('{"content":{"a":1}}'));

    expect(publicApi($http)->apiSearch(searchQuery())->isEmpty())->toBeTrue()
        ->and(fn () => publicApi($http)->apiSearch(searchQuery()))->toThrow(UninterpretableResponseException::class);
});

it('builds public requests with the PSR-17 factories it is given', function () {
    $factory = new class implements RequestFactoryInterface
    {
        public function createRequest(string $method, $uri): RequestInterface
        {
            return (new HttpFactory)->createRequest($method, $uri)->withHeader('X-Built-By', 'app');
        }
    };
    $http = (new FakeHttpClient)->queue(Responses::json('[]'));

    (new PublicApiClient(new ConnectionSettings(baseUrl: 'https://datadis.test'), $http, $factory))->apiSearch(searchQuery());

    expect($http->lastRequest()->getHeaderLine('X-Built-By'))->toBe('app');
});

it('uses the public Datadis host by default', function () {
    $http = (new FakeHttpClient)->queue(Responses::json('[]'));

    (new PublicApiClient(http: $http))->apiSearch(searchQuery());

    expect($http->lastRequest()->getUri()->getHost())->toBe('datadis.es');
});

it('sends public requests to the configured host', function () {
    $http = (new FakeHttpClient)->queue(Responses::json('[]'));

    publicApi($http)->apiSearch(searchQuery());

    expect($http->lastRequest()->getUri()->getHost())->toBe('datadis.test');
});

it('refuses an empty object, which says nothing about the page', function () {
    publicApi((new FakeHttpClient)->queue(Responses::json('{}')))->apiSearch(searchQuery());
})->throws(UninterpretableResponseException::class);

it('sends sums without paging', function () {
    $http = (new FakeHttpClient)->queue(Responses::json(datadisFixture('public/sum-search.json')), Responses::json(datadisFixture('public/sum-search-auto.json')));

    $sum = publicApi($http)->apiSumSearch(searchQuery());
    publicApi($http)->apiSumSearchAuto(autoQuery());

    parse_str($http->requests()[0]->getUri()->getQuery(), $first);
    parse_str($http->requests()[1]->getUri()->getQuery(), $second);

    expect($first)->not->toHaveKey('page')->and($second)->not->toHaveKey('pageSize')
        ->and($sum->records[0]->sumContracts())->toBe(5977431);
});

it('logs in and sends the token when it is given credentials', function () {
    $http = (new FakeHttpClient)->queue(
        Responses::text(Tokens::jwt(['exp' => time() + 3600])),
        Responses::json(datadisFixture('public/search.json')),
    );
    $api = new PublicApiClient(new DatadisConfig('12345678Z', 'secret', baseUrl: 'https://datadis.test'), $http);

    $result = $api->apiSearch(searchQuery());

    expect($http->requests())->toHaveCount(2)
        ->and($http->requests()[0]->getUri()->getPath())->toBe('/nikola-auth/tokens/login')
        ->and($http->lastRequest()->getHeaderLine('Authorization'))->toStartWith('Bearer ')
        ->and($result->records[0]->date()?->format('Y-m-d'))->toBe('2022-04-16');
});
