<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Data;

use DateTimeImmutable;
use DateTimeZone;
use Lenorix\DatadisClient\Decoding\Fields;
use Lenorix\DatadisClient\Values\Cups;
use SensitiveParameter;

/**
 * A supply point of the account (or of an authorized third party).
 *
 * `distributorCode` and `pointType` exist only here, yet the other endpoints require them, so a
 * supply is the starting point of every other call.
 */
final readonly class Supply
{
    use HidesPersonalData;

    /** Shown as [hidden] in dumps: see HidesPersonalData. */
    private const array PERSONAL_FIELDS = ['cups', 'address', 'postalCode', 'raw'];

    /** @param array<array-key, mixed> $raw */
    private function __construct(
        public string $cups,
        public ?string $address,
        public ?string $postalCode,
        public ?string $province,
        public ?string $provinceCode,
        public ?string $municipality,
        public ?string $municipioCode,
        public ?string $distributor,
        public ?DateTimeImmutable $validDateFrom,
        public ?DateTimeImmutable $validDateTo,
        private bool $openEnded,
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

    /**
     * Whether the CUPS, the distributor code and the point type are present and usable: all that
     * consumption needs. Contract detail, maximum power and reactive data only need the CUPS and
     * the distributor code.
     *
     * @phpstan-assert-if-true !null $this->distributorCode
     * @phpstan-assert-if-true !null $this->pointType
     */
    public function isQueryable(): bool
    {
        return Cups::isValid($this->cups) && self::isValidDistributorCode($this->distributorCode) && self::isValidPointType($this->pointType);
    }

    /**
     * Datadis codes are opaque short strings (`"1"`..`"8"` today): any text without spaces or
     * control characters, up to 20 characters, so a new code still goes through.
     */
    public static function isValidDistributorCode(?string $code): bool
    {
        return $code !== null && preg_match('/^[^\s\x00-\x1F\x7F]{1,20}$/Du', $code) === 1;
    }

    /** Metering point types 1 to 5 (RD 1110/2007). */
    public static function isValidPointType(?int $type): bool
    {
        return $type !== null && $type >= 1 && $type <= 5;
    }
}
