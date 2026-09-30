<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Data;

use Lenorix\DatadisClient\Exceptions\UninterpretableResponseException;
use SensitiveParameter;

/**
 * The reactive energy answer (v2 only). Least verified response of the API: the field names come
 * from the manual and no real success body has been captured. It is usually empty for domestic supplies.
 */
final readonly class ReactiveEnergy
{
    /**
     * @param  list<ReactiveEnergyEntry>  $energy
     * @param  array<array-key, mixed>  $raw
     */
    private function __construct(
        public ?string $cups,
        public array $energy,
        public ?string $code,
        public ?string $code_desc,
        public array $raw,
    ) {}

    /**
     * @param  array<array-key, mixed>  $row  the `reactiveEnergy` object
     * @return self|null null when the object is empty
     */
    public static function fromRow(#[SensitiveParameter] array $row): ?self
    {
        if ($row === []) {
            return null;
        }

        $energy = [];
        $items = $row['energy'] ?? null;

        if (is_array($items)) {
            foreach ($items as $item) {
                if (is_array($item)) {
                    $energy[] = ReactiveEnergyEntry::fromRow($item);
                }
            }
        }

        return new self(
            Fields::text($row, 'cups'),
            $energy,
            Fields::text($row, 'code'),
            Fields::text($row, 'code_desc'),
            $row,
        );
    }

    /**
     * Reads a whole reactive answer: `{"reactiveEnergy": {...}}`, `{"reactiveEnergy": [{...}, ...]}`,
     * or only distributor errors. Anything else, a bare list included, is an error, never an empty
     * result.
     *
     * @internal
     *
     * @param  array<array-key, mixed>  $decoded
     * @return ApiResult<self>
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
            $record = is_array($object) ? Envelope::decodeRow(self::fromRow(...), $object, $endpoint) : null;

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
