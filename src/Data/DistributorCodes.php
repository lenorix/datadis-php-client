<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Data;

use Lenorix\DatadisClient\Exceptions\UninterpretableResponseException;

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
    public static function result(array $decoded, string $endpoint): ApiResult
    {
        $lists = [];

        if ($decoded !== [] && array_is_list($decoded)) {
            foreach ($decoded as $item) {
                if (is_array($item)) {
                    $lists[] = $item['distributorCodes'] ?? [];
                }
            }
        } elseif (isset($decoded['distExistenceUser']) && is_array($decoded['distExistenceUser'])) {
            $lists[] = $decoded['distExistenceUser']['distributorCodes'] ?? [];
        } elseif (array_key_exists('distributorCodes', $decoded)) {
            $lists[] = $decoded['distributorCodes'];
        } elseif ($decoded !== [] && ! array_key_exists('distributorError', $decoded) && ! array_key_exists('distExistenceUser', $decoded)) {
            throw new UninterpretableResponseException("{$endpoint}: the answer has no distributor codes.", endpoint: $endpoint);
        }

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
}
