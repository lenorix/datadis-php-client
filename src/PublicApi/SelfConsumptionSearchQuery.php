<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\PublicApi;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Query of the public self-consumption searches (`api-search-auto`, `api-sum-search-auto`).
 * Same rules as PublicSearchQuery, with self-consumption types and province as filters.
 */
final readonly class SelfConsumptionSearchQuery
{
    /** Self-consumption modality codes of the public code list. */
    public const array SELF_CONSUMPTION_TYPES = [
        '31', '32', '33', '41', '42', '43', '51', '52', '53', '54', '55', '56', '57', '58',
        '61', '62', '63', '64', '71', '72', '73', '74', '77',
    ];

    /** A copy taken when the query is built, so it always shows the dates that are sent. */
    public DateTimeImmutable $startDate;

    public DateTimeImmutable $endDate;

    /** @var array<string, string|int> */
    private array $query;

    /**
     * @param  array<Community>  $community  one or two
     * @param  array<string>  $distributor  CNMC distributor codes
     * @param  array<string>  $selfConsumption  see SELF_CONSUMPTION_TYPES
     * @param  array<string>  $province  two digit province codes
     * @param  array<string>  $sort  field names, a leading `-` for descending
     */
    public function __construct(
        DateTimeInterface $startDate,
        DateTimeInterface $endDate,
        public array $community,
        public int $page = 0,
        public int $pageSize = QueryRules::MAX_PAGE_SIZE,
        public array $distributor = [],
        public array $selfConsumption = [],
        public array $province = [],
        public array $sort = [],
    ) {
        $this->startDate = DateTimeImmutable::createFromInterface($startDate);
        $this->endDate = DateTimeImmutable::createFromInterface($endDate);
        QueryRules::dates($this->startDate, $this->endDate);
        QueryRules::paging($page, $pageSize);

        $types = '/^('.implode('|', self::SELF_CONSUMPTION_TYPES).')$/D';

        $this->query = array_filter([
            'startDate' => $this->startDate->format('Y/m/d'),
            'endDate' => $this->endDate->format('Y/m/d'),
            'page' => $page,
            'pageSize' => $pageSize,
            'community' => QueryRules::communities($community),
            'distributor' => QueryRules::list('distributor', $distributor, '/^[A-Za-z0-9]{1,10}$/D'),
            'selfConsumption' => QueryRules::list('self-consumption type', $selfConsumption, $types),
            'province' => QueryRules::list('province', $province, '/^\d{2}$/D'),
            'sort' => QueryRules::sort($sort),
        ], static fn (string|int|null $value): bool => $value !== null);
    }

    /** @return array<string, string|int> */
    public function toQuery(): array
    {
        return $this->query;
    }

    /**
     * The query for `api-sum-search-auto`, which takes no paging and no sorting.
     *
     * @return array<string, string|int>
     */
    public function toSumQuery(): array
    {
        return array_diff_key($this->query, ['page' => true, 'pageSize' => true, 'sort' => true]);
    }
}
