<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\PublicApi;

use Generator;
use Lenorix\DatadisClient\ConnectionSettings;
use Lenorix\DatadisClient\Data\ApiResult;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Exceptions\NoDataException;
use Lenorix\DatadisClient\Exceptions\UninterpretableResponseException;
use Lenorix\DatadisClient\Http\ApiCaller;
use Lenorix\DatadisClient\Http\Connection;
use Lenorix\DatadisClient\Http\RequestFactory;
use Lenorix\DatadisClient\Http\ResponseClassifier;
use Lenorix\DatadisClient\Http\Transport;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\SimpleCache\CacheInterface;
use SensitiveParameter;

/**
 * The public Datadis API: aggregated open data by territory, tariff, sector...
 *
 * It exists only in the v1 style. The answer shapes follow the official manual's samples (see
 * PublicRecord); the answer is still read tolerantly (a list, a list inside `content`, `data`,
 * `results` or `items`, or a single object) and every row is kept whole.
 *
 * The official manual asks for the login token on these calls too, and the clients seen in the
 * wild send it. Give a DatadisConfig to log in and send it; give only ConnectionSettings to call
 * without credentials.
 */
final class PublicApi
{
    private const string PATH = '/api-public/';

    /** TOLERATED, NO SOURCE: keys under which a paged answer might carry its rows (the manual shows a bare list). */
    private const array LIST_KEYS = ['content', 'data', 'results', 'items'];

    private readonly RequestFactory $requests;

    private readonly Transport $transport;

    /** Set when credentials were given: calls then carry the login token. */
    private readonly ?ApiCaller $caller;

    public function __construct(
        DatadisConfig|ConnectionSettings|null $settings = null,
        ?ClientInterface $http = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?CacheInterface $tokenCache = null,
    ) {
        $settings ??= new ConnectionSettings;
        $connection = new Connection($settings, $http, $requestFactory, $streamFactory);

        $this->requests = $connection->requests;
        $this->transport = $connection->transport;
        $this->caller = $settings instanceof DatadisConfig ? $connection->caller($settings, $tokenCache) : null;
    }

    /** @return ApiResult<PublicRecord> */
    public function apiSearch(PublicSearchQuery $query): ApiResult
    {
        return $this->call('api-search', $query->toQuery());
    }

    /** @return ApiResult<PublicRecord> */
    public function apiSumSearch(PublicSearchQuery $query): ApiResult
    {
        return $this->call('api-sum-search', $query->toSumQuery());
    }

    /** @return ApiResult<PublicRecord> */
    public function apiSearchAuto(SelfConsumptionSearchQuery $query): ApiResult
    {
        return $this->call('api-search-auto', $query->toQuery());
    }

    /** @return ApiResult<PublicRecord> */
    public function apiSumSearchAuto(SelfConsumptionSearchQuery $query): ApiResult
    {
        return $this->call('api-sum-search-auto', $query->toSumQuery());
    }

    /**
     * Every record of api-search, page after page from the query's page, until a page comes back
     * shorter than the page size or $maxPages pages were read.
     *
     * @return Generator<int, PublicRecord>
     */
    public function apiSearchAll(PublicSearchQuery $query, int $maxPages = 1000): Generator
    {
        return $this->walk(fn (int $page) => $this->apiSearch($query->withPage($page)), $query->page, $query->pageSize, $maxPages);
    }

    /**
     * Every record of api-search-auto, page after page. See apiSearchAll().
     *
     * @return Generator<int, PublicRecord>
     */
    public function apiSearchAutoAll(SelfConsumptionSearchQuery $query, int $maxPages = 1000): Generator
    {
        return $this->walk(fn (int $page) => $this->apiSearchAuto($query->withPage($page)), $query->page, $query->pageSize, $maxPages);
    }

    /**
     * @param  callable(int): ApiResult<PublicRecord>  $fetch
     * @return Generator<int, PublicRecord>
     */
    private function walk(callable $fetch, int $firstPage, int $pageSize, int $maxPages): Generator
    {
        $key = 0;

        for ($read = 0, $page = $firstPage; $read < $maxPages; $read++, $page++) {
            $result = $fetch($page);

            // Plain yields with a running key: `yield from` would restart keys at 0 on every page.
            foreach ($result->records as $record) {
                yield $key++ => $record;
            }

            if ($result->count() + $result->skippedRows < $pageSize) {
                return;
            }
        }
    }

    /**
     * @param  array<string, string|int>  $query
     * @return ApiResult<PublicRecord>
     */
    private function call(string $endpoint, array $query): ApiResult
    {
        try {
            $decoded = $this->caller === null
                ? ResponseClassifier::decode($this->transport->send($this->requests->publicGet(self::PATH.$endpoint, $query), $endpoint), $endpoint)
                : $this->caller->get(self::PATH.$endpoint, $query, $endpoint);
        } catch (NoDataException $e) {
            if ($e->httpStatus !== null && $e->httpStatus >= 200 && $e->httpStatus < 300) {
                return new ApiResult([]);
            }

            throw $e;
        }

        $rows = self::rows($decoded, $endpoint);
        $records = [];
        $skipped = 0;

        foreach ($rows as $row) {
            if (is_array($row) && $row !== []) {
                $records[] = PublicRecord::fromRow($row);
            } else {
                $skipped++;
            }
        }

        return new ApiResult($records, [], $skipped, $decoded);
    }

    /**
     * @param  array<array-key, mixed>  $decoded
     * @return list<mixed>
     */
    private static function rows(#[SensitiveParameter] array $decoded, string $endpoint): array
    {
        if ($decoded === [] || array_is_list($decoded)) {
            return $decoded;
        }

        foreach (self::LIST_KEYS as $key) {
            if (array_key_exists($key, $decoded)) {
                $rows = $decoded[$key];

                if (! is_array($rows) || ($rows !== [] && ! array_is_list($rows))) {
                    throw new UninterpretableResponseException("{$endpoint}: \"{$key}\" is not a list.", endpoint: $endpoint);
                }

                return $rows;
            }
        }

        // TOLERATED, NO SOURCE: a single object is read as one row.
        return [$decoded];
    }
}
