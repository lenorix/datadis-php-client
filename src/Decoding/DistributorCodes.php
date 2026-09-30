<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Decoding;

use Lenorix\DatadisClient\Data\ApiResult;
use Lenorix\DatadisClient\Exceptions\UninterpretableResponseException;
use SensitiveParameter;

/**
 * Reads the distributors-with-supplies answer, which has a different shape in each version:
 * v2 `{"distExistenceUser": {"distributorCodes": [...]}, "distributorError": [...]}` and
 * v1 `{"distributorCodes": [...]}` (or a list wrapping that object). Codes stay opaque strings.
 *
 * @internal
 */
final class DistributorCodes
{
    /**
     * @param  array<array-key, mixed>  $decoded
     * @return ApiResult<string>
     */
    public static function result(#[SensitiveParameter] array $decoded, string $endpoint): ApiResult
    {
        $lists = match (true) {
            $decoded === [] => [],
            // TOLERATED, NO SOURCE: a v1 answer wrapped in a list.
            array_is_list($decoded) => self::fromList($decoded, $endpoint),
            array_key_exists('distExistenceUser', $decoded) => [self::codesOf($decoded['distExistenceUser'], $endpoint)],
            array_key_exists('distributorCodes', $decoded) => [$decoded['distributorCodes'] ?? []],
            array_key_exists('distributorError', $decoded) => [],
            default => throw new UninterpretableResponseException("{$endpoint}: the answer has no distributor codes.", endpoint: $endpoint),
        };

        $codes = [];
        $skipped = 0;

        foreach ($lists as $list) {
            if (! is_array($list)) {
                throw new UninterpretableResponseException("{$endpoint}: \"distributorCodes\" is not a list.", endpoint: $endpoint);
            }

            foreach ($list as $code) {
                $text = Fields::nonEmptyText(['c' => $code], 'c');

                if ($text === null) {
                    $skipped++;

                    continue;
                }

                $codes[] = $text;
            }
        }

        return new ApiResult($codes, Envelope::distributorErrors($decoded), $skipped, $decoded);
    }

    /**
     * A list of codes, or a list of objects that each carry `distributorCodes`.
     *
     * @param  list<mixed>  $items
     * @return list<mixed>
     */
    private static function fromList(#[SensitiveParameter] array $items, string $endpoint): array
    {
        $lists = [];
        $scalars = [];

        foreach ($items as $item) {
            if (is_array($item)) {
                $lists[] = self::codesOf($item, $endpoint);
            } else {
                $scalars[] = $item;
            }
        }

        return $scalars === [] ? $lists : [...$lists, $scalars];
    }

    /** The codes of `{"distributorCodes": [...]}`, of a plain list, or nothing for an empty value. */
    private static function codesOf(mixed $value, string $endpoint): mixed
    {
        return match (true) {
            $value === null, $value === [] => [],
            // TOLERATED, NO SOURCE: `distExistenceUser` as a bare list of codes.
            is_array($value) && array_is_list($value) => $value,
            is_array($value) && array_key_exists('distributorCodes', $value) => $value['distributorCodes'] ?? [],
            default => throw new UninterpretableResponseException("{$endpoint}: the answer has no distributor codes.", endpoint: $endpoint),
        };
    }
}
