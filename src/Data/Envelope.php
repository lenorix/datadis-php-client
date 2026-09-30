<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Data;

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

        foreach ($rows as $row) {
            $record = is_array($row) ? self::decodeRow($decodeRow, $row, $endpoint) : null;

            if ($record === null) {
                $skipped++;

                continue;
            }

            $records[] = $record;
        }

        if ($rows !== [] && $records === []) {
            throw new UninterpretableResponseException("{$endpoint}: none of the {$skipped} rows could be used.", endpoint: $endpoint);
        }

        return new ApiResult($records, $errors, $skipped, $decoded);
    }

    /**
     * @param  array<array-key, mixed>  $decoded
     * @return array{list<mixed>, list<DistributorError>}
     */
    public static function open(#[SensitiveParameter] array $decoded, string $key, string $endpoint): array
    {
        if ($decoded === []) {
            return [[], []];
        }

        if (array_is_list($decoded)) {
            return [$decoded, []];
        }

        $errors = self::distributorErrors($decoded);

        if (array_key_exists($key, $decoded)) {
            $rows = $decoded[$key] ?? [];

            if (! is_array($rows) || ($rows !== [] && ! array_is_list($rows))) {
                throw new UninterpretableResponseException("{$endpoint}: \"{$key}\" is not a list.", endpoint: $endpoint);
            }

            return [$rows, $errors];
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

        // Documented as a list of objects, but a failure must not be lost because of its shape.
        if (is_string($raw) && trim($raw) !== '') {
            return [DistributorError::fromRow(['errorDescription' => $raw])];
        }

        if (! is_array($raw)) {
            return [];
        }

        // Documented as a list, but a single error object must not be lost.
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
