<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\PublicApi;

use Generator;
use GuzzleHttp\Psr7\HttpFactory;
use Lenorix\DatadisClient\ConnectionSettings;
use Lenorix\DatadisClient\Data\ApiResult;
use Lenorix\DatadisClient\Exceptions\NoDataException;
use Lenorix\DatadisClient\Exceptions\UninterpretableResponseException;
use Lenorix\DatadisClient\Http\GuzzleClientFactory;
use Lenorix\DatadisClient\Http\RequestFactory;
use Lenorix\DatadisClient\Http\ResponseClassifier;
use Lenorix\DatadisClient\Http\Transport;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The public, unauthenticated Datadis API: aggregated open data by territory, tariff, sector...
 *
 * It exists only in the v1 style. UNVERIFIED: the parameters come from the captured specification,
 * but no source has a real success body, so the answer is read tolerantly (a list, a list inside
 * `content`, `data` or `results`, or a single object) and every row is kept whole.
 */
final class PublicApi
{
    private const string PATH = '/api-public/';

    /** Keys under which a paged answer might carry its rows. */
    private const array LIST_KEYS = ['content', 'data', 'results', 'items'];

    private readonly RequestFactory $requests;

    private readonly Transport $transport;

    public function __construct(
        ?ConnectionSettings $settings = null,
        ?ClientInterface $http = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $settings ??= new ConnectionSettings;
        $factory = new HttpFactory;

        $this->requests = new RequestFactory($settings, $requestFactory ?? $factory, $streamFactory ?? $factory);
        $this->transport = new Transport($http ?? GuzzleClientFactory::create($settings));
    }

    /** @return ApiResult<PublicRecord> */
    public function search(PublicSearchQuery $query): ApiResult
    {
        return $this->call('api-search', $query->toQuery());
    }

    /** @return ApiResult<PublicRecord> */
    public function sumSearch(PublicSearchQuery $query): ApiResult
    {
        return $this->call('api-sum-search', $query->toQuery());
    }

    /** @return ApiResult<PublicRecord> */
    public function searchSelfConsumption(SelfConsumptionSearchQuery $query): ApiResult
    {
        return $this->call('api-search-auto', $query->toQuery());
    }

    /** @return ApiResult<PublicRecord> */
    public function sumSearchSelfConsumption(SelfConsumptionSearchQuery $query): ApiResult
    {
        return $this->call('api-sum-search-auto', $query->toQuery());
    }

    /**
     * Every record of api-search, page after page from the query's page, until a page comes back
     * shorter than the page size or $maxPages pages were read.
     *
     * @return Generator<int, PublicRecord>
     */
    public function searchAll(PublicSearchQuery $query, int $maxPages = 1000): Generator
    {
        return $this->walk(fn (int $page) => $this->search($query->withPage($page)), $query->page, $query->pageSize, $maxPages);
    }

    /**
     * Every record of api-search-auto, page after page. See searchAll().
     *
     * @return Generator<int, PublicRecord>
     */
    public function searchSelfConsumptionAll(SelfConsumptionSearchQuery $query, int $maxPages = 1000): Generator
    {
        return $this->walk(fn (int $page) => $this->searchSelfConsumption($query->withPage($page)), $query->page, $query->pageSize, $maxPages);
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
        $response = $this->transport->send($this->requests->publicGet(self::PATH.$endpoint, $query), $endpoint);

        try {
            $decoded = ResponseClassifier::decode($response, $endpoint);
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
    private static function rows(array $decoded, string $endpoint): array
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

        return [$decoded];
    }
}
