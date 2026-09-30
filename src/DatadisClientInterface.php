<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient;

use DateTimeInterface;
use Lenorix\DatadisClient\Data\ApiResult;
use Lenorix\DatadisClient\Data\Authorization;
use Lenorix\DatadisClient\Data\ConsumptionReading;
use Lenorix\DatadisClient\Data\ContractDetail;
use Lenorix\DatadisClient\Data\Group;
use Lenorix\DatadisClient\Data\MaxPowerReading;
use Lenorix\DatadisClient\Data\ReactiveEnergy;
use Lenorix\DatadisClient\Data\Supply;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\MeasurementType;
use Lenorix\DatadisClient\Values\Nif;

/**
 * The calls of the Datadis private API. Depend on this in application code so tests can stand in
 * for the client; DatadisClient documents each call.
 */
interface DatadisClientInterface
{
    /** @return ApiResult<Supply> */
    public function supplies(?Nif $authorizedNif = null, ?string $distributorCode = null): ApiResult;

    public function findSupply(Cups $cups, ?Nif $authorizedNif = null): ?Supply;

    /** @return ApiResult<string> */
    public function distributors(?Nif $authorizedNif = null): ApiResult;

    /** @return ApiResult<ContractDetail> */
    public function contractDetail(Cups $cups, string $distributorCode, ?Nif $authorizedNif = null): ApiResult;

    /** @return ApiResult<ConsumptionReading> */
    public function consumption(
        Cups $cups,
        string $distributorCode,
        int $pointType,
        Month $from,
        ?Month $to = null,
        MeasurementType $measurementType = MeasurementType::Hourly,
        ?Nif $authorizedNif = null,
    ): ApiResult;

    /** @return ApiResult<MaxPowerReading> */
    public function maxPower(Cups $cups, string $distributorCode, Month $from, ?Month $to = null, ?Nif $authorizedNif = null): ApiResult;

    /** @return ApiResult<ReactiveEnergy> */
    public function reactive(Cups $cups, string $distributorCode, Month $from, ?Month $to = null, ?Nif $authorizedNif = null): ApiResult;

    /** @return ApiResult<ContractDetail> */
    public function contractDetailOf(Supply $supply, ?Nif $authorizedNif = null): ApiResult;

    /** @return ApiResult<ConsumptionReading> */
    public function consumptionOf(
        Supply $supply,
        Month $from,
        ?Month $to = null,
        MeasurementType $measurementType = MeasurementType::Hourly,
        ?Nif $authorizedNif = null,
    ): ApiResult;

    /** @return ApiResult<MaxPowerReading> */
    public function maxPowerOf(Supply $supply, Month $from, ?Month $to = null, ?Nif $authorizedNif = null): ApiResult;

    /** @return ApiResult<ReactiveEnergy> */
    public function reactiveOf(Supply $supply, Month $from, ?Month $to = null, ?Nif $authorizedNif = null): ApiResult;

    public function newAuthorization(Nif $authorizedNif, ?DateTimeInterface $from = null, ?DateTimeInterface $to = null, Cups ...$cups): string;

    public function cancelAuthorization(Nif $authorizedNif, Cups ...$cups): string;

    /** @return ApiResult<Authorization> */
    public function authorizations(?Nif $ownerNif = null): ApiResult;

    /** @return ApiResult<Group> */
    public function groups(): ApiResult;

    /** @return array<array-key, mixed> */
    public function partnerUsers(): array;

    public function partnerDeleteUser(Nif $nif): string;

    public function partnerAgreementDate(?Nif $nif = null): string;
}
