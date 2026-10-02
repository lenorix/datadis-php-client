<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient;

use Generator;
use Lenorix\DatadisClient\Data\ApiResult;
use Lenorix\DatadisClient\Decoding\Envelope;
use Lenorix\DatadisClient\Exceptions\InvalidRequestException;
use Lenorix\DatadisClient\Exceptions\NoDataException;
use Lenorix\DatadisClient\Exceptions\UninterpretableResponseException;
use Lenorix\DatadisClient\Http\ApiCaller;
use Lenorix\DatadisClient\PublicApi\PublicRecord;
use Lenorix\DatadisClient\PublicApi\PublicSearchQuery;
use Lenorix\DatadisClient\PublicApi\SelfConsumptionSearchQuery;
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
 * It needs the account like the private API: without the login token Datadis answers 401
 * (verified, October 2026).
 */
final class PublicApiClient
{
    private const string PATH = '/api-public/';

    /** TOLERATED, NO SOURCE: keys under which a paged answer might carry its rows (the manual shows a bare list). */
    private const array LIST_KEYS = ['content', 'data', 'results', 'items'];

    /** Logs in with the account and sends its token on every call: the public API requires it. */
    private readonly ApiCaller $caller;

    public function __construct(
        DatadisConfig $config,
        ?ClientInterface $http = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?CacheInterface $tokenCache = null,
    ) {
        $this->caller = ApiCaller::connect($config, $http, $requestFactory, $streamFactory, $tokenCache);
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
     *
     * @throws InvalidRequestException when $maxPages is below 1
     */
    public function apiSearchAll(PublicSearchQuery $query, int $maxPages = 1000): Generator
    {
        return $this->walk('api-search', $query->toQuery(), $maxPages);
    }

    /**
     * Every record of api-search-auto, page after page. See apiSearchAll().
     *
     * @return Generator<int, PublicRecord>
     *
     * @throws InvalidRequestException when $maxPages is below 1
     */
    public function apiSearchAutoAll(SelfConsumptionSearchQuery $query, int $maxPages = 1000): Generator
    {
        return $this->walk('api-search-auto', $query->toQuery(), $maxPages);
    }

    /**
     * Reads page after page of a search, from the query's page on, until a short page or the limit.
     *
     * @param  array<string, string|int>  $query
     * @return Generator<int, PublicRecord>
     */
    private function walk(string $endpoint, array $query, int $maxPages): Generator
    {
        // Refused at the call, not at the first read: a limit that reads no page would look like no data.
        if ($maxPages < 1) {
            throw new InvalidRequestException("The page limit must be at least 1, {$maxPages} given.");
        }

        return $this->pages($endpoint, $query, $maxPages);
    }

    /**
     * @param  array<string, string|int>  $query
     * @return Generator<int, PublicRecord>
     */
    private function pages(string $endpoint, array $query, int $maxPages): Generator
    {
        for ($read = 0, $page = (int) $query['page']; $read < $maxPages; $read++, $page++) {
            $result = $this->call($endpoint, ['page' => $page] + $query);

            // A generator numbers plain yields on its own, so keys run on across pages.
            foreach ($result->records as $record) {
                yield $record;
            }

            if ($result->count() + $result->skippedRows < (int) $query['pageSize']) {
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
            $decoded = $this->caller->get(self::PATH.$endpoint, $query, $endpoint);
        } catch (NoDataException $e) {
            // A 404 is a failure of the public API; a 2xx without a body is an empty page.
            if ($e->httpStatus === 404) {
                throw $e;
            }

            return new ApiResult([]);
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

        if ($records === [] && $skipped > 0) {
            throw new UninterpretableResponseException("{$endpoint}: none of the {$skipped} rows could be read.", endpoint: $endpoint);
        }

        return new ApiResult($records, [], $skipped, $decoded);
    }

    /**
     * @param  array<array-key, mixed>  $decoded
     * @return list<mixed>
     */
    private static function rows(#[SensitiveParameter] array $decoded, string $endpoint): array
    {
        if (array_is_list($decoded)) {
            return $decoded;
        }

        foreach (self::LIST_KEYS as $key) {
            if (array_key_exists($key, $decoded)) {
                return Envelope::listAt($decoded, $key, $endpoint);
            }
        }

        // TOLERATED, NO SOURCE: a single object is read as one row, but only with a field of a
        // record: a 200 with {"message": "maintenance"} is a failure, not a record without data.
        if (! PublicRecord::looksLikeOne($decoded)) {
            throw new UninterpretableResponseException("{$endpoint}: the answer is an object without the fields of a record.", endpoint: $endpoint);
        }

        return [$decoded];
    }
}
