<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Data;

use DateTimeImmutable;
use DateTimeZone;
use Lenorix\DatadisClient\Decoding\Fields;
use Lenorix\DatadisClient\Support\Decimal;
use Lenorix\DatadisClient\Tariff\AccessFareParser;
use Lenorix\DatadisClient\Tariff\AccessTariff;
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
    /**
     * @param  list<string|null>  $contractedPowerkW  decimal strings, scale 2
     * @param  string|null  $installedCapacity  decimal string, scale 3, in the unit Datadis sends: the
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
            Fields::text($row, 'accessFare', 'accesFare'),
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
     * The access tariff, when the `accessFare` description and the number of contracted powers agree
     * (2 for 2.0TD, 6 for the others). Null means it could not be told apart safely.
     */
    public function tariff(): ?AccessTariff
    {
        $tariff = $this->accessFare === null ? null : AccessFareParser::parse($this->accessFare);

        return $tariff !== null && count($this->contractedPowerkW) === $tariff->powerPeriods() ? $tariff : null;
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
