<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\PublicApi;

use DateTimeInterface;

/**
 * Query of the public self-consumption searches (`api-search-auto`, `api-sum-search-auto`).
 * Same rules as PublicSearchQuery, with self-consumption types and provinces as filters.
 */
final readonly class SelfConsumptionSearchQuery
{
    /** Self-consumption modality codes of the public code list. */
    public const array SELF_CONSUMPTION_TYPES = [
        '31', '32', '33', '41', '42', '43', '51', '52', '53', '54', '55', '56', '57', '58',
        '61', '62', '63', '64', '71', '72', '73', '74', '77',
    ];

    /** @var array<string, string|int> */
    private array $query;

    /**
     * @param  array<Community>  $communities  one or two
     * @param  array<string>  $distributors  CNMC distributor codes
     * @param  array<string>  $selfConsumptionTypes  see SELF_CONSUMPTION_TYPES
     * @param  array<string>  $provinces  two digit province codes
     * @param  array<string>  $sort  field names, a leading `-` for descending
     */
    public function __construct(
        public DateTimeInterface $from,
        public DateTimeInterface $to,
        public array $communities,
        public int $page = 0,
        public int $pageSize = QueryRules::MAX_PAGE_SIZE,
        public array $distributors = [],
        public array $selfConsumptionTypes = [],
        public array $provinces = [],
        public array $sort = [],
    ) {
        QueryRules::dates($from, $to);
        QueryRules::paging($page, $pageSize);

        $types = '/^('.implode('|', self::SELF_CONSUMPTION_TYPES).')$/D';

        $this->query = array_filter([
            'startDate' => $from->format('Y/m/d'),
            'endDate' => $to->format('Y/m/d'),
            'page' => $page,
            'pageSize' => $pageSize,
            'community' => QueryRules::communities($communities),
            'distributor' => QueryRules::list('distributor', $distributors, '/^[A-Za-z0-9]{1,10}$/D'),
            'selfConsumption' => QueryRules::list('self-consumption type', $selfConsumptionTypes, $types),
            'province' => QueryRules::list('province', $provinces, '/^\d{2}$/D'),
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

    public function withPage(int $page): self
    {
        return new self(
            $this->from, $this->to, $this->communities, $page, $this->pageSize,
            $this->distributors, $this->selfConsumptionTypes, $this->provinces, $this->sort,
        );
    }
}
