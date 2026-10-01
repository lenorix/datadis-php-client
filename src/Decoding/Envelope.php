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
     * @return ApiResult<T>
     *
     * @throws UninterpretableResponseException
     */
    public static function build(#[SensitiveParameter] array $decoded, string $key, string $endpoint, callable $decodeRow): ApiResult
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

            $record = is_array($row) ? self::decodeRow($decodeRow, $row, $endpoint) : null;

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

        if (array_key_exists('distributorError', $decoded)) {
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

        // TOLERATED, NO SOURCE: documented as a list of objects; text is accepted so a failure is not lost.
        if (is_string($raw) && trim($raw) !== '') {
            return [DistributorError::fromRow(['errorDescription' => $raw])];
        }

        if (! is_array($raw)) {
            return [];
        }

        // TOLERATED, NO SOURCE: documented as a list; a single error object is accepted so it is not lost.
        if ($raw !== [] && ! array_is_list($raw)) {
            $raw = [$raw];
        }

        $errors = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                $errors[] = DistributorError::fromRow($item);
            }
        }

        return $errors;
    }

    /**
     * Whether every field of a row is empty: an empty or blank string, null or an empty list.
     *
     * @param  array<array-key, mixed>  $row
     */
    /**
     * Every field empty, null or an empty list: Datadis's way of sending nothing (verified for
     * contract detail and reactive data).
     *
     * @param  array<array-key, mixed>  $row
     */
    public static function isBlank(#[SensitiveParameter] array $row): bool
    {
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
    public static function decodeRow(callable $decodeRow, #[SensitiveParameter] array $row, string $endpoint): mixed
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
