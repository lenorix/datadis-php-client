<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\PublicApi;

use DateTimeImmutable;
use DateTimeZone;
use Lenorix\DatadisClient\Decoding\Fields;
use Lenorix\DatadisClient\Time\Month;
use SensitiveParameter;

/**
 * One aggregated row of the public API.
 *
 * Shapes from the official manual's sample answers: searches carry `dataDay`, `dataMonth`,
 * `dataYear`, the filters, `sumEnergy`, `sumContracts` (and `sumPower` for self-consumption) and
 * the hourly totals `mi1`..`mi25` (the last one for the extra hour of the autumn change), all
 * numbers as strings; sums carry `sumEnergy`, `sumContract` (singular) and `sumPower` as numbers.
 * The row is kept whole in `raw`.
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

    /** Any numeric field as an exact decimal string with at least $minScale decimals. */
    public function decimal(string $field, int $minScale = 3): ?string
    {
        return Fields::decimal($this->raw, $minScale, $field);
    }

    /** The day of an aggregated row, from `dataDay`, `dataMonth` and `dataYear`; null for sums. */
    public function date(): ?DateTimeImmutable
    {
        $day = Fields::integer($this->raw, 'dataDay');
        $month = Fields::integer($this->raw, 'dataMonth');
        $year = Fields::integer($this->raw, 'dataYear');

        if ($day === null || $month === null || $year === null || $year > 9999 || ! checkdate($month, $day, $year)) {
            return null;
        }

        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day), new DateTimeZone(Month::SERVICE_TIME_ZONE));
    }

    /** Energy in kWh with three decimals. */
    public function sumEnergy(): ?string
    {
        return $this->decimal('sumEnergy');
    }

    /** Generation power in kW (self-consumption searches only), with three decimals. */
    public function sumPower(): ?string
    {
        return $this->decimal('sumPower');
    }

    /** Number of contracts; searches spell it `sumContracts` and sums `sumContract`. */
    public function sumContracts(): ?int
    {
        return Fields::integer($this->raw, 'sumContracts', 'sumContract');
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
