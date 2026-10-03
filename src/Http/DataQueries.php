<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Http;

use DateTimeImmutable;
use DateTimeZone;
use Lenorix\DatadisClient\ApiVersion;
use Lenorix\DatadisClient\Data\Supply;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Exceptions\InvalidRequestException;
use Lenorix\DatadisClient\Exceptions\OutOfContractRangeException;
use Lenorix\DatadisClient\Exceptions\OutOfServedRangeException;
use Lenorix\DatadisClient\Exceptions\UnsupportedOperationException;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\MeasurementType;
use Lenorix\DatadisClient\Values\Nif;
use Psr\Clock\ClockInterface;
use SensitiveParameter;

/**
 * Builds the query of every data call, and checks it, before anything is sent: the one place the
 * calls, the remember...() imports and the ...BlockedUntil() lookups share, so they always agree
 * on the query, and so on its 24 hour key. No input or output here.
 *
 * @internal
 */
final readonly class DataQueries
{
    public function __construct(
        private DatadisConfig $config,
        private ClockInterface $clock,
        private ApiVersion $version,
        private ?Nif $holder = null,
    ) {}

    /** The same rules, for the client of a holder who authorized the account (see DatadisClient::forHolder()). */
    public function withHolder(Nif $holder): self
    {
        return new self($this->config, $this->clock, $this->version, $holder);
    }

    /** authorizedNif is only for a third party's supplies: for the account itself it must be omitted. */
    public function authorized(?Nif $nif): ?string
    {
        if ($nif !== null && $this->holder !== null && ! $nif->equals($this->holder)) {
            throw new InvalidRequestException('This client reads the supplies of one holder; use forHolder() for another one.');
        }

        $nif ??= $this->holder;

        return $nif === null || $nif->value() === $this->config->username() ? null : $nif->value();
    }

    public function assertThirdParty(Nif $nif): void
    {
        if ($nif->value() === $this->config->username()) {
            throw new InvalidRequestException('An authorization is for a third party, not for the account itself.');
        }
    }

    /**
     * @param  array<Cups>  $cups
     * @return list<string>
     */
    public function cupsList(array $cups): array
    {
        $values = array_values(array_map(static fn (Cups $c): string => $c->value(), $cups));

        if (count(array_unique($values)) !== count($values)) {
            throw new InvalidRequestException('The same CUPS is listed more than once.');
        }

        return $values;
    }

    /**
     * The CUPS and distributor code every call of a supply needs. Datadis refuses with a 400 a range
     * that starts before the month the contract starts (verified), and the refusal still counts for
     * 24 hours, so it is refused here first.
     *
     * @return array{Cups, string}
     */
    public function queryable(#[SensitiveParameter] Supply $supply, ?Month $startDate = null, ?Month $endDate = null): array
    {
        if (! Cups::isValid($supply->cups) || ! Supply::isValidDistributorCode($supply->distributorCode) || $supply->distributorCode === null) {
            throw new InvalidRequestException('The supply was listed without a usable CUPS or distributor code; list the supplies again.');
        }

        if ($startDate !== null && $supply->validDateFrom !== null && $startDate->isBefore(Month::fromDate($supply->validDateFrom))) {
            throw new OutOfContractRangeException(
                'The range starts before the contract of the supply ('.Month::fromDate($supply->validDateFrom)->format().'); Datadis refuses it, and the refusal counts for 24 hours. MonthPlanner::ranges() keeps to the contract.',
                contractStart: Month::fromDate($supply->validDateFrom),
            );
        }

        // REPORTED by a production consumer: a month after the contract ended is refused like one before it.
        $last = $endDate ?? $startDate;

        if ($last !== null && $supply->validDateTo !== null && $last->isAfter(Month::fromDate($supply->validDateTo))) {
            throw new OutOfContractRangeException(
                'The range ends after the contract of the supply ('.Month::fromDate($supply->validDateTo)->format().'); Datadis refuses it, and the refusal counts for 24 hours. MonthPlanner::ranges() keeps to the contract.',
                contractEnd: Month::fromDate($supply->validDateTo),
            );
        }

        return [Cups::fromString($supply->cups), $supply->distributorCode];
    }

    /** Only consumption takes the point type. */
    public function pointTypeOf(#[SensitiveParameter] Supply $supply): int
    {
        if ($supply->pointType === null || ! Supply::isValidPointType($supply->pointType)) {
            throw new InvalidRequestException('The supply was listed without a usable point type, which consumption needs; list the supplies again.');
        }

        return $supply->pointType;
    }

    /**
     * The consumption query exactly as it is sent, and remembered: one place, so both agree.
     *
     * @return array<string, string|int|null>
     */
    public function consumption(
        Cups $cups,
        string $distributorCode,
        int $pointType,
        Month $startDate,
        ?Month $endDate,
        MeasurementType $measurementType,
        ?Nif $authorizedNif,
        bool $served = true,
    ): array {
        $query = $this->month($cups, $distributorCode, $startDate, $endDate, $served);
        $this->assertPointType($pointType);

        return $query + [
            'measurementType' => $measurementType->value,
            'pointType' => $pointType,
            'authorizedNif' => $this->authorized($authorizedNif),
        ];
    }

    /**
     * The maximum power or reactive query exactly as it is sent, and remembered.
     *
     * @return array<string, string|null>
     */
    public function power(Cups $cups, string $distributorCode, Month $startDate, ?Month $endDate, ?Nif $authorizedNif, bool $served = true): array
    {
        return $this->month($cups, $distributorCode, $startDate, $endDate, $served) + ['authorizedNif' => $this->authorized($authorizedNif)];
    }

    /**
     * The part every month-range data call shares, after checking it: one month when there is no end.
     *
     * @return array{cups: string, distributorCode: string, startDate: string, endDate: string}
     */
    private function month(Cups $cups, string $distributorCode, Month $startDate, ?Month $endDate, bool $served = true): array
    {
        $endDate ??= $startDate;
        $this->assertDistributorCode($distributorCode);
        $this->assertRange($startDate, $endDate, $served);

        return [
            'cups' => $cups->value(),
            'distributorCode' => $distributorCode,
            'startDate' => $startDate->format(),
            'endDate' => $endDate->format(),
        ];
    }

    /**
     * Reactive data exists only in v2. Its 24 hour key is the maximum power one, so remembering or
     * looking it up on v1 would block or report a maximum power query instead.
     */
    public function assertReactive(): void
    {
        if ($this->version !== ApiVersion::V2) {
            throw new UnsupportedOperationException('Reactive data exists only in API v2.');
        }
    }

    public function assertDistributorCode(string $code): void
    {
        if (! Supply::isValidDistributorCode($code)) {
            throw new InvalidRequestException('The distributor code must be 1 to 20 characters, without spaces or control characters.');
        }
    }

    private function assertPointType(int $pointType): void
    {
        if (! Supply::isValidPointType($pointType)) {
            throw new InvalidRequestException("The point type must be between 1 and 5, {$pointType} given.");
        }
    }

    /**
     * Datadis serves the last 24 months (the boundary month is refused) and no future month. A query
     * remembered from the past is not checked against them: it may have left the window since.
     */
    public function assertRange(Month $startDate, Month $endDate, bool $served = true): void
    {
        if ($startDate->isAfter($endDate)) {
            throw new InvalidRequestException('The first month must not be after the last one.');
        }

        if (! $served) {
            return;
        }

        $now = $this->now();

        foreach ([$startDate, $endDate] as $month) {
            if (! $month->isWithinHistory($now)) {
                throw new OutOfServedRangeException('Datadis only serves the last '.Month::HISTORY_MONTHS." months up to the current one; {$month->format()} is outside that window.", $month);
            }
        }
    }

    /**
     * The current moment on the Madrid calendar. Datadis is a Spanish service and is assumed to
     * judge its month window by the Madrid calendar even for Canary Islands data (UNVERIFIED).
     */
    private function now(): DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new DateTimeZone(Month::SERVICE_TIME_ZONE));
    }
}
