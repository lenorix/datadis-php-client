<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Data;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Lenorix\DatadisClient\Decoding\Fields;
use Lenorix\DatadisClient\Support\Decimal;
use Lenorix\DatadisClient\Tariff\AccessTariff;
use Lenorix\DatadisClient\Tariff\StandardTariffResolver;
use Lenorix\DatadisClient\Tariff\TariffResolver;
use Lenorix\DatadisClient\Time\DatadisDate;
use SensitiveParameter;

/**
 * The contract of a supply point. Most fields are nullable because Datadis fills them inconsistently.
 *
 * `accessFare` is free text describing the voltage and power band, not a tariff code.
 * `contractedPowerkW` keeps the position of every value (position = power period), so a value that
 * could not be read is null rather than removed.
 */
final readonly class ContractDetail
{
    use HidesPersonalData;

    /** Shown as [hidden] in dumps: see HidesPersonalData. */
    private const array PERSONAL_FIELDS = ['cups', 'postalCode', 'cau', 'raw'];

    /**
     * @param  list<string|null>  $contractedPowerkW  exact decimal strings, at least two decimals
     * @param  string|null  $installedCapacity  exact decimal string, at least three decimals, in the unit Datadis sends: the
     *                                          documentation names it in kW, but its only sample (`1.12E7`)
     *                                          looks like W (UNVERIFIED)
     * @param  list<array{startDate: DateTimeImmutable|null, endDate: DateTimeImmutable|null}>  $dateOwner
     * @param  array<array-key, mixed>  $raw
     */
    private function __construct(
        public string $cups,
        public ?string $distributor,
        public ?string $marketer,
        public ?string $tension,
        public ?string $accessFare,
        public ?string $province,
        public ?string $municipality,
        public ?string $postalCode,
        public array $contractedPowerkW,
        public ?string $timeDiscrimination,
        public ?string $modePowerControl,
        public ?DateTimeImmutable $startDate,
        public ?DateTimeImmutable $endDate,
        private bool $openEnded,
        public ?string $codeFare,
        public ?string $selfConsumptionTypeCode,
        public ?string $selfConsumptionTypeDesc,
        public ?string $section,
        public ?string $subsection,
        public ?string $partitionCoefficient,
        public ?string $cau,
        public ?string $installedCapacity,
        public array $dateOwner,
        public ?DateTimeImmutable $lastMarketerDate,
        public ?string $maxPowerInstall,
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
            Fields::text($row, 'distributor'),
            Fields::text($row, 'marketer'),
            Fields::text($row, 'tension'),
            // The misspelt key is used when the right one is missing or empty.
            Fields::nonEmptyText($row, 'accessFare') ?? Fields::nonEmptyText($row, 'accesFare') ?? Fields::text($row, 'accessFare', 'accesFare'),
            Fields::text($row, 'province'),
            Fields::text($row, 'municipality'),
            Fields::text($row, 'postalCode'),
            self::powers($row['contractedPowerkW'] ?? null),
            Fields::text($row, 'timeDiscrimination'),
            Fields::text($row, 'modePowerControl'),
            Fields::date($row, $zone, 'startDate'),
            Fields::date($row, $zone, 'endDate'),
            Fields::nonEmptyText($row, 'endDate') === null,
            Fields::text($row, 'codeFare'),
            Fields::text($row, 'selfConsumptionTypeCode'),
            Fields::text($row, 'selfConsumptionTypeDesc'),
            Fields::text($row, 'section'),
            Fields::text($row, 'subsection'),
            Fields::decimal($row, 6, 'partitionCoefficient'),
            Fields::text($row, 'cau'),
            Fields::decimal($row, 3, 'installedCapacityKW', 'installedCapacity'),
            self::dateOwner($row['dateOwner'] ?? null, $zone),
            Fields::date($row, $zone, 'lastMarketerDate'),
            Fields::decimal($row, 3, 'maxPowerInstall'),
            $row,
        );
    }

    /**
     * The access tariff, read by a TariffResolver: the package's StandardTariffResolver unless you
     * give your own. Null when the contract does not tell it for sure. `accessFare` and `codeFare`
     * always keep the text as received, for a reading of your own.
     *
     * @throws InvalidArgumentException from a resolver that cannot read a text (see TariffResolver)
     */
    public function tariff(?TariffResolver $resolver = null): ?AccessTariff
    {
        return ($resolver ?? new StandardTariffResolver)->resolve($this);
    }

    /** `endDate` was empty or null: the contract has no end. */
    public function isOpenEnded(): bool
    {
        return $this->openEnded;
    }

    /** @return list<string|null> */
    private static function powers(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_map(static fn (mixed $power): ?string => Decimal::tryOf($power, 2), array_values($value));
    }

    /** @return list<array{startDate: DateTimeImmutable|null, endDate: DateTimeImmutable|null}> */
    private static function dateOwner(mixed $value, DateTimeZone $zone): array
    {
        if (! is_array($value)) {
            return [];
        }

        $periods = [];
        foreach ($value as $period) {
            if (is_array($period)) {
                $periods[] = [
                    'startDate' => self::ownerDate($period['startDate'] ?? null, $zone),
                    'endDate' => self::ownerDate($period['endDate'] ?? null, $zone),
                ];
            }
        }

        return $periods;
    }

    /** Ownership periods use dashes (`2022-01-01`), unlike every other date, but slashes are accepted too. */
    private static function ownerDate(mixed $value, DateTimeZone $zone): ?DateTimeImmutable
    {
        return is_string($value) ? DatadisDate::tryParse($value, $zone) : null;
    }
}
