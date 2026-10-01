<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Data;

use Lenorix\DatadisClient\Decoding\Fields;
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

    /**
     * Error code 8, "No existen datos en el periodo solicitado": the distributor has no data for
     * that period, which is an answer and not a failure (seen once, October 2026).
     */
    public function isNoData(): bool
    {
        return $this->errorCode === '8';
    }
}
