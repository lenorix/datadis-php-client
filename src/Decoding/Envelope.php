<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Decoding;

use Lenorix\DatadisClient\Data\ApiResult;
use Lenorix\DatadisClient\Data\DistributorError;
use Lenorix\DatadisClient\Exceptions\DatadisException;
use Lenorix\DatadisClient\Exceptions\UninterpretableResponseException;
use SensitiveParameter;
use Throwable;

/**
 * Opens the two shapes a list answer comes in: the v2 envelope `{"<key>": [...], "distributorError": [...]}`
 * and the v1 bare list `[...]`.
 *
 * @internal
 */
final class Envelope
{
    /**
     * @template T of object|string
     *
     * @param  array<array-key, mixed>  $decoded
     * @param  callable(array<array-key, mixed>): (T|null)  $decodeRow  returns the record, or null for an unusable row
     * @param  (callable(array<array-key, mixed>): bool)|null  $holdsNoData  true for a row the decoder left out
     *                                                                       because it holds no data by design:
     *                                                                       counted like a blank row, never a fault
     * @return ApiResult<T>
     *
     * @throws UninterpretableResponseException
     */
    public static function build(#[SensitiveParameter] array $decoded, string $key, string $endpoint, #[SensitiveParameter] callable $decodeRow, ?callable $holdsNoData = null): ApiResult
    {
        [$rows, $errors] = self::open($decoded, $key, $endpoint);

        $records = [];
        $skipped = 0;
        $blank = 0;

        foreach ($rows as $row) {
            if (is_array($row) && self::isBlank($row)) {
                // Datadis answers a CUPS it cannot see with one row whose fields are all empty
                // (verified): it carries no data and is not a failure.
                $blank++;

                continue;
            }

            // Every row goes through the decoder, even one that turns out to hold nothing: a decoder
            // may count the rows it sees (the repeated hour of the autumn change day).
            $record = is_array($row) ? self::decodeRow($decodeRow, $row, $endpoint) : null;

            if ($record === null && is_array($row) && $holdsNoData !== null && $holdsNoData($row)) {
                $blank++;

                continue;
            }

            if ($record === null) {
                $skipped++;

                continue;
            }

            $records[] = $record;
        }

        if ($skipped > 0 && $records === []) {
            throw new UninterpretableResponseException("{$endpoint}: none of the {$skipped} rows could be used.", endpoint: $endpoint);
        }

        return new ApiResult($records, $errors, $skipped + $blank, $decoded);
    }

    /**
     * The list under $key; null counts as an empty list.
     *
     * @param  array<array-key, mixed>  $decoded
     * @return list<mixed>
     */
    public static function listAt(#[SensitiveParameter] array $decoded, string $key, string $endpoint): array
    {
        $rows = $decoded[$key] ?? [];

        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new UninterpretableResponseException("{$endpoint}: \"{$key}\" is not a list.", endpoint: $endpoint);
        }

        return $rows;
    }

    /**
     * @param  array<array-key, mixed>  $decoded
     * @return array{list<mixed>, list<DistributorError>}
     */
    private static function open(#[SensitiveParameter] array $decoded, string $key, string $endpoint): array
    {
        // v1 answers are bare lists (verified), an empty one included.
        if (array_is_list($decoded)) {
            return [$decoded, []];
        }

        $errors = self::distributorErrors($decoded);

        if (array_key_exists($key, $decoded)) {
            return [self::listAt($decoded, $key, $endpoint), $errors];
        }

        // A distributor that failed may leave the list out: the errors are the answer. Without
        // errors, an answer missing its list is another endpoint's or a changed one, not "no data".
        if ($errors !== []) {
            return [[], $errors];
        }

        throw new UninterpretableResponseException("{$endpoint}: the answer has no \"{$key}\" list.", endpoint: $endpoint);
    }

    /**
     * @param  array<array-key, mixed>  $decoded
     * @return list<DistributorError>
     */
    public static function distributorErrors(#[SensitiveParameter] array $decoded): array
    {
        $raw = $decoded['distributorError'] ?? [];

        // TOLERATED, NO SOURCE: documented as a list of objects. A single object, text or any other
        // value still counts as a failure, so an answer that reports one is never read as "no data".
        $items = is_array($raw) && array_is_list($raw) ? $raw : [$raw];

        $errors = [];
        foreach ($items as $item) {
            if ($item === null || $item === false || (is_string($item) && trim($item) === '')) {
                continue;
            }

            $errors[] = DistributorError::fromRow(is_array($item) ? $item : ['errorDescription' => Fields::scalar($item)]);
        }

        return $errors;
    }

    /**
     * Every field empty, null or an empty list: Datadis's way of sending nothing (verified for
     * contract detail and reactive data).
     *
     * @param  array<array-key, mixed>  $row
     */
    public static function isBlank(#[SensitiveParameter] array $row): bool
    {
        // The blank row Datadis sends has its fields, all empty; a row with no field at all is not it.
        if ($row === []) {
            return false;
        }

        foreach ($row as $value) {
            if (! ($value === null || $value === [] || (is_string($value) && trim($value) === ''))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Runs a row decoder so that nothing but a DatadisException can escape it.
     *
     * @template T
     *
     * @param  callable(array<array-key, mixed>): T  $decodeRow
     * @param  array<array-key, mixed>  $row
     * @return T
     */
    public static function decodeRow(#[SensitiveParameter] callable $decodeRow, #[SensitiveParameter] array $row, string $endpoint): mixed
    {
        try {
            return $decodeRow($row);
        } catch (DatadisException $e) {
            throw $e;
        } catch (Throwable $e) {
            // Anything that escapes a row decoder must not leave the package as a raw TypeError.
            throw new UninterpretableResponseException("{$endpoint}: a row could not be decoded (".$e::class.').', endpoint: $endpoint, previous: $e);
        }
    }
}
