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

        // A bare list has neither key either.
        if (! array_key_exists('reactiveEnergy', $decoded) && ! array_key_exists('distributorError', $decoded)) {
            throw new UninterpretableResponseException("{$endpoint}: the answer has no \"reactiveEnergy\".", endpoint: $endpoint);
        }

        $value = $decoded['reactiveEnergy'] ?? [];

        if (! is_array($value)) {
            throw new UninterpretableResponseException("{$endpoint}: \"reactiveEnergy\" is not an object.", endpoint: $endpoint);
        }

        // TOLERATED, NO SOURCE: documented as one object; a list of them is read too.
        $objects = array_is_list($value) ? $value : [$value];

        $records = [];
        $skipped = 0;
        $blank = 0;

        foreach ($objects as $object) {
            // A period without data comes as an object whose every field is null (verified).
            if (is_array($object) && Envelope::isBlank($object)) {
                $blank++;

                continue;
            }

            $record = is_array($object) ? Envelope::decodeRow(ReactiveEnergy::fromRow(...), $object, $endpoint) : null;

            if ($record === null) {
                $skipped++;
            } else {
                $records[] = $record;
            }
        }

        if ($skipped > 0 && $records === []) {
            throw new UninterpretableResponseException("{$endpoint}: none of the {$skipped} reactive entries could be used.", endpoint: $endpoint);
        }

        return new ApiResult($records, Envelope::distributorErrors($decoded), $skipped + $blank, $decoded);
    }
}
