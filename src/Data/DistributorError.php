<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Data;

use SensitiveParameter;

/**
 * A distributor that failed inside an otherwise successful (HTTP 200) v2 answer, for example
 * "Error interno distribuidora". It is data, not an exception: the rest of the answer is still valid.
 */
final readonly class DistributorError
{
    /** @param array<array-key, mixed> $raw */
    private function __construct(
        public ?string $distributorCode,
        public ?string $distributorName,
        public ?string $errorCode,
        public ?string $errorDescription,
        public array $raw,
    ) {}

    /** @param array<array-key, mixed> $row */
    public static function fromRow(#[SensitiveParameter] array $row): self
    {
        return new self(
            Fields::text($row, 'distributorCode'),
            Fields::text($row, 'distributorName'),
            Fields::text($row, 'errorCode'),
            Fields::text($row, 'errorDescription'),
            $row,
        );
    }
}
