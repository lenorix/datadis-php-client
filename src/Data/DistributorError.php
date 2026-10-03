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
    use HidesPersonalData;

    /** Shown as [hidden] in dumps: see HidesPersonalData. */
    private const array PERSONAL_FIELDS = ['raw'];

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
     * Error code 8 with "No existen datos en el periodo solicitado": the distributor has no data
     * for that period, which is an answer and not a failure (seen once, October 2026, for one
     * distributor). Codes are each distributor's, so the code alone is not enough: only that code
     * with that description is recognised, and anything else is a failure until an answer shows
     * otherwise. The code and its description are kept as sent, for an application to read its own way.
     */
    public function isNoData(): bool
    {
        return $this->errorCode !== null
            && ltrim(trim($this->errorCode), '0') === '8'
            && preg_match('/^\s*no\s+existen\s+datos\b/iu', $this->errorDescription ?? '') === 1;
    }
}
