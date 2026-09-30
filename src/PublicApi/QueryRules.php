<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\PublicApi;

use DateTimeInterface;
use Lenorix\DatadisClient\Exceptions\InvalidRequestException;

/**
 * Validation shared by the public search queries. Rules come from the captured API specification.
 *
 * @internal
 */
final class QueryRules
{
    public const int MAX_PAGE_SIZE = 2000;

    public const array SORT_FIELDS = [
        'dataDate', 'community', 'province', 'municipality', 'postalCode', 'fare', 'measurePointType',
        'tension', 'economicSector', 'timeDiscrimination', 'distributor', 'sumEnergy', 'sumContracts',
        // Field names of the answers, which the current documentation lists as sort options.
        'dataDay', 'dataMonth', 'dataYear', 'selfConsumption', 'sumPower', 'measurePointType',
    ];

    public static function dates(DateTimeInterface $from, DateTimeInterface $to): void
    {
        if ($from->format('Y-m-d') > $to->format('Y-m-d')) {
            throw new InvalidRequestException('The start date must not be after the end date.');
        }
    }

    public static function paging(int $page, int $pageSize): void
    {
        if ($page < 0) {
            throw new InvalidRequestException('The first page is 0; a negative page is not valid.');
        }

        if ($pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE) {
            throw new InvalidRequestException('The page size must be between 1 and '.self::MAX_PAGE_SIZE.'.');
        }
    }

    /**
     * Community is mandatory and at most two can be combined.
     *
     * @param  array<Community>  $communities
     */
    public static function communities(array $communities): string
    {
        $codes = array_values(array_map(static fn (Community $c): string => $c->value, $communities));

        if ($codes === [] || count($codes) > 2 || count(array_unique($codes)) !== count($codes)) {
            throw new InvalidRequestException('Give one or two different communities.');
        }

        return implode(',', $codes);
    }

    /**
     * Joins the values with commas after checking each one against $pattern. Null when there are none.
     *
     * @param  array<mixed>  $values  checked at runtime: callers may pass anything
     */
    public static function list(string $name, array $values, string $pattern): ?string
    {
        if ($values === []) {
            return null;
        }

        foreach ($values as $value) {
            if (! is_string($value) || preg_match($pattern, $value) !== 1) {
                throw new InvalidRequestException("Invalid {$name} value.");
            }
        }

        return implode(',', $values);
    }

    /** @param array<mixed> $fields checked at runtime: callers may pass anything */
    public static function sort(array $fields): ?string
    {
        foreach ($fields as $field) {
            if (! is_string($field) || ! in_array(ltrim($field, '-'), self::SORT_FIELDS, true) || str_starts_with($field, '--')) {
                throw new InvalidRequestException('Unknown sort field.');
            }
        }

        return $fields === [] ? null : implode(',', $fields);
    }
}
