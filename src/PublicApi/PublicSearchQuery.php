<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\PublicApi;

use DateTimeInterface;

/**
 * Query of the public aggregated searches (`api-search`, `api-sum-search`).
 *
 * Dates are whole days (`YYYY/MM/DD`) taken from each instant in its own time zone. Every list is
 * sent comma-separated. Values are checked locally so a wrong query never leaves the machine.
 */
final readonly class PublicSearchQuery
{
    /** @var array<string, string|int> */
    private array $query;

    /**
     * @param  array<Community>  $communities  one or two
     * @param  array<string>  $measurementTypes  measurement point types `01` to `05`
     * @param  array<string>  $distributors  CNMC distributor codes, such as `0172`
     * @param  array<string>  $fares  tariff codes, such as `2T`
     * @param  array<string>  $provinceMunicipalities  a province (`03`) or a province + municipality (`03133`)
     * @param  array<string>  $postalCodes
     * @param  array<string>  $economicSectors  `1` residential, `2` industrial, `3` services, `4` not specified
     * @param  array<string>  $tensions  `E0` (low voltage) to `E6`
     * @param  array<string>  $timeDiscriminations  `G0`, `E1`, `E2`, `E3`
     * @param  array<string>  $sort  field names, a leading `-` for descending
     * @param  bool|null  $groupByPostalCode  sent as 1 or 0 when given
     */
    public function __construct(
        public DateTimeInterface $from,
        public DateTimeInterface $to,
        public array $communities,
        public array $measurementTypes = [],
        public int $page = 0,
        public int $pageSize = QueryRules::MAX_PAGE_SIZE,
        public array $distributors = [],
        public array $fares = [],
        public array $provinceMunicipalities = [],
        public array $postalCodes = [],
        public array $economicSectors = [],
        public array $tensions = [],
        public array $timeDiscriminations = [],
        public array $sort = [],
        public ?bool $groupByPostalCode = null,
    ) {
        QueryRules::dates($from, $to);
        QueryRules::paging($page, $pageSize);

        $this->query = array_filter([
            'startDate' => $from->format('Y/m/d'),
            'endDate' => $to->format('Y/m/d'),
            'page' => $page,
            'pageSize' => $pageSize,
            'community' => QueryRules::communities($communities),
            'measurementType' => QueryRules::list('measurement type', $measurementTypes, '/^0[1-5]$/D'),
            'distributor' => QueryRules::list('distributor', $distributors, '/^[A-Za-z0-9]{1,10}$/D'),
            'fare' => QueryRules::list('fare', $fares, '/^[A-Za-z0-9.]{1,6}$/D'),
            'provinceMunicipality' => QueryRules::list('province or municipality', $provinceMunicipalities, '/^(\d{2}|\d{5})$/D'),
            'postalCode' => QueryRules::list('postal code', $postalCodes, '/^\d{5}$/D'),
            'economicSector' => QueryRules::list('economic sector', $economicSectors, '/^[1-4]$/D'),
            'tension' => QueryRules::list('tension', $tensions, '/^E[0-6]$/D'),
            'timeDiscrimination' => QueryRules::list('time discrimination', $timeDiscriminations, '/^(G0|E1|E2|E3)$/D'),
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

    public function withPage(int $page): self
    {
        return new self(
            $this->from, $this->to, $this->communities, $this->measurementTypes, $page, $this->pageSize,
            $this->distributors, $this->fares, $this->provinceMunicipalities, $this->postalCodes,
            $this->economicSectors, $this->tensions, $this->timeDiscriminations, $this->sort, $this->groupByPostalCode,
        );
    }
}
