<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Decoding;

use Lenorix\DatadisClient\Data\ApiResult;
use Lenorix\DatadisClient\Data\ReactiveEnergy;
use Lenorix\DatadisClient\Exceptions\UninterpretableResponseException;
use SensitiveParameter;

/** @internal */
final class ReactiveEnergyAnswer
{
    /**
     * Reads a whole reactive answer: `{"reactiveEnergy": {...}}`, `{"reactiveEnergy": [{...}, ...]}`,
     * or only distributor errors. Anything else, a bare list included, is an error, never an empty
     * result.
     *
     * @param  array<array-key, mixed>  $decoded
     * @return ApiResult<ReactiveEnergy>
     *
     * @throws UninterpretableResponseException
     */
    public static function result(#[SensitiveParameter] array $decoded, string $endpoint): ApiResult
    {
        if ($decoded === []) {
            return new ApiResult([]);
        }

        if (array_is_list($decoded)) {
            throw new UninterpretableResponseException("{$endpoint}: the answer is not an object.", endpoint: $endpoint);
        }

        $value = array_key_exists('reactiveEnergy', $decoded) ? $decoded['reactiveEnergy'] : null;

        if (! array_key_exists('reactiveEnergy', $decoded) && ! array_key_exists('distributorError', $decoded)) {
            throw new UninterpretableResponseException("{$endpoint}: the answer has no \"reactiveEnergy\".", endpoint: $endpoint);
        }

        if ($value !== null && ! is_array($value)) {
            throw new UninterpretableResponseException("{$endpoint}: \"reactiveEnergy\" is not an object.", endpoint: $endpoint);
        }

        $objects = match (true) {
            $value === null, $value === [] => [],
            // TOLERATED, NO SOURCE: documented as one object; a list of them is read too.
            array_is_list($value) => $value,
            default => [$value],
        };

        $records = [];
        $skipped = 0;

        foreach ($objects as $object) {
            $record = is_array($object) ? Envelope::decodeRow(ReactiveEnergy::fromRow(...), $object, $endpoint) : null;

            if ($record === null) {
                $skipped++;
            } else {
                $records[] = $record;
            }
        }

        if ($objects !== [] && $records === []) {
            throw new UninterpretableResponseException("{$endpoint}: none of the {$skipped} reactive entries could be used.", endpoint: $endpoint);
        }

        return new ApiResult($records, Envelope::distributorErrors($decoded), $skipped, $decoded);
    }
}
