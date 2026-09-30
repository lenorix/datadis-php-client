<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\PublicApi;

use Lenorix\DatadisClient\Data\Fields;
use SensitiveParameter;

/**
 * One aggregated row of the public API.
 *
 * UNVERIFIED: no source has a real success body, so the row is kept whole (`raw`) and read through
 * generic accessors. The documented aggregates are `sumEnergy`, `sumContracts` and the hourly
 * totals `mi1`..`mi25` (25 buckets, the last one for the extra hour of the autumn change).
 */
final readonly class PublicRecord
{
    public const int HOURLY_BUCKETS = 25;

    /** @param array<array-key, mixed> $raw */
    private function __construct(public array $raw) {}

    /** @param array<array-key, mixed> $row */
    public static function fromRow(#[SensitiveParameter] array $row): self
    {
        return new self($row);
    }

    public function text(string $field): ?string
    {
        return Fields::text($this->raw, $field);
    }

    public function decimal(string $field, int $scale = 3): ?string
    {
        return Fields::decimal($this->raw, $scale, $field);
    }

    /** @return array<int, string|null> bucket number (1 to 25) => decimal string with scale 3, or null */
    public function hourly(): array
    {
        $buckets = [];
        for ($i = 1; $i <= self::HOURLY_BUCKETS; $i++) {
            $buckets[$i] = $this->decimal("mi{$i}");
        }

        return $buckets;
    }
}
