<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Data;

use DateTimeImmutable;
use DateTimeZone;
use SensitiveParameter;

/**
 * A supply point of the account (or of an authorized third party).
 *
 * `distributorCode` and `pointType` exist only here, yet the other endpoints require them, so a
 * supply is the starting point of every other call.
 */
final readonly class Supply
{
    /** @param array<array-key, mixed> $raw */
    private function __construct(
        public string $cups,
        public ?string $address,
        public ?string $postalCode,
        public ?string $province,
        public ?string $provinceCode,
        public ?string $municipality,
        public ?string $municipalityCode,
        public ?string $distributor,
        public ?DateTimeImmutable $validFrom,
        public ?DateTimeImmutable $validTo,
        public bool $openEnded,
        public ?int $pointType,
        public ?string $distributorCode,
        public array $raw,
    ) {}

    /**
     * @param  array<array-key, mixed>  $row
     * @return self|null null when the row has no CUPS
     */
    public static function fromRow(#[SensitiveParameter] array $row, DateTimeZone $zone): ?self
    {
        $cups = Fields::nonEmptyText($row, 'cups');

        if ($cups === null) {
            return null;
        }

        return new self(
            $cups,
            Fields::text($row, 'address'),
            Fields::text($row, 'postalCode'),
            Fields::text($row, 'province'),
            Fields::text($row, 'provinceCode'),
            Fields::text($row, 'municipality'),
            Fields::text($row, 'municipioCode', 'municipalityCode'),
            Fields::text($row, 'distributor'),
            Fields::date($row, $zone, 'validDateFrom'),
            Fields::date($row, $zone, 'validDateTo'),
            Fields::nonEmptyText($row, 'validDateTo') === null,
            Fields::integer($row, 'pointType'),
            Fields::nonEmptyText($row, 'distributorCode'),
            $row,
        );
    }

    /** `validDateTo` was empty: the contract has no end. */
    public function isOpenEnded(): bool
    {
        return $this->openEnded;
    }

    /** Whether the two values every other endpoint asks for are present. */
    public function isQueryable(): bool
    {
        return $this->distributorCode !== null && $this->pointType !== null;
    }
}
