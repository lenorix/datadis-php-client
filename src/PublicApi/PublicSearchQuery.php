<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\PublicApi;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Query of the public aggregated searches (`api-search`, `api-sum-search`).
 *
 * Dates are whole days (`YYYY/MM/DD`) taken from each instant in its own time zone. Every list is
 * sent comma-separated. Values are checked locally so a wrong query never leaves the machine.
 */
final readonly class PublicSearchQuery
{
    /** A copy taken when the query is built, so it always shows the dates that are sent. */
    public DateTimeImmutable $startDate;

    public DateTimeImmutable $endDate;

    /** @var array<string, string|int> */
    private array $query;

    /**
     * @param  array<Community>  $community  one or two
     * @param  array<string>  $measurementType  measurement point types `01` to `05`
     * @param  array<string>  $distributor  CNMC distributor codes, such as `0172`
     * @param  array<string>  $fare  tariff codes, such as `2T`
     * @param  array<string>  $provinceMunicipality  a province (`03`) or a province + municipality (`03133`)
     * @param  array<string>  $postalCode
     * @param  array<string>  $economicSector  `1` residential, `2` industrial, `3` services, `4` not specified
     * @param  array<string>  $tension  `E0` (low voltage) to `E6`
     * @param  array<string>  $timeDiscrimination  `G0`, `E1`, `E2`, `E3`
     * @param  array<string>  $sort  field names, a leading `-` for descending
     * @param  bool|null  $groupByPostalCode  sent as 1 or 0 when given
     */
    public function __construct(
        DateTimeInterface $startDate,
        DateTimeInterface $endDate,
        public array $community,
        public array $measurementType = [],
        public int $page = 0,
        public int $pageSize = QueryRules::MAX_PAGE_SIZE,
        public array $distributor = [],
        public array $fare = [],
        public array $provinceMunicipality = [],
        public array $postalCode = [],
        public array $economicSector = [],
        public array $tension = [],
        public array $timeDiscrimination = [],
        public array $sort = [],
        public ?bool $groupByPostalCode = null,
    ) {
        $this->startDate = DateTimeImmutable::createFromInterface($startDate);
        $this->endDate = DateTimeImmutable::createFromInterface($endDate);
        QueryRules::dates($this->startDate, $this->endDate);
        QueryRules::paging($page, $pageSize);

        $this->query = array_filter([
            'startDate' => $this->startDate->format('Y/m/d'),
            'endDate' => $this->endDate->format('Y/m/d'),
            'page' => $page,
            'pageSize' => $pageSize,
            'community' => QueryRules::communities($community),
            'measurementType' => QueryRules::list('measurement type', $measurementType, '/^0[1-5]$/D'),
            'distributor' => QueryRules::list('distributor', $distributor, '/^[A-Za-z0-9]{1,10}$/D'),
            'fare' => QueryRules::list('fare', $fare, '/^[A-Za-z0-9.]{1,6}$/D'),
            'provinceMunicipality' => QueryRules::list('province or municipality', $provinceMunicipality, '/^(\d{2}|\d{5})$/D'),
            'postalCode' => QueryRules::list('postal code', $postalCode, '/^\d{5}$/D'),
            'economicSector' => QueryRules::list('economic sector', $economicSector, '/^[1-4]$/D'),
            'tension' => QueryRules::list('tension', $tension, '/^E[0-6]$/D'),
            'timeDiscrimination' => QueryRules::list('time discrimination', $timeDiscrimination, '/^(G0|E1|E2|E3)$/D'),
            'groupByPostalCode' => $groupByPostalCode === null ? null : (int) $groupByPostalCode,
            'sort' => QueryRules::sort($sort),
        ], static fn (string|int|null $value): bool => $value !== null);
    }

    /** @return array<string, string|int> */
    public function toQuery(): array
    {
        return $this->query;
    }

    /**
     * The query for `api-sum-search`, which takes no paging.
     *
     * @return array<string, string|int>
     */
    public function toSumQuery(): array
    {
        return array_diff_key($this->query, ['page' => true, 'pageSize' => true]);
    }
}
