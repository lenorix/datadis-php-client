<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use Lenorix\DatadisClient\Data\ApiResult;
use Lenorix\DatadisClient\Data\Authorization;
use Lenorix\DatadisClient\Data\ConsumptionReading;
use Lenorix\DatadisClient\Data\ContractDetail;
use Lenorix\DatadisClient\Data\Group;
use Lenorix\DatadisClient\Data\MaxPowerReading;
use Lenorix\DatadisClient\Data\PartnerUser;
use Lenorix\DatadisClient\Data\ReactiveEnergy;
use Lenorix\DatadisClient\Data\Supply;
use Lenorix\DatadisClient\Data\SupplyMatcher;
use Lenorix\DatadisClient\Decoding\DistributorCodes;
use Lenorix\DatadisClient\Decoding\Envelope;
use Lenorix\DatadisClient\Decoding\Fields;
use Lenorix\DatadisClient\Decoding\ReactiveEnergyAnswer;
use Lenorix\DatadisClient\Exceptions\AuthenticationException;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Lenorix\DatadisClient\Exceptions\DatadisException;
use Lenorix\DatadisClient\Exceptions\InvalidRequestException;
use Lenorix\DatadisClient\Exceptions\LedgerUnavailableException;
use Lenorix\DatadisClient\Exceptions\NoDataException;
use Lenorix\DatadisClient\Exceptions\NothingToRefreshException;
use Lenorix\DatadisClient\Exceptions\OutOfContractRangeException;
use Lenorix\DatadisClient\Exceptions\OutOfServedRangeException;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\DatadisClient\Exceptions\ServiceUnavailableException;
use Lenorix\DatadisClient\Exceptions\UninterpretableResponseException;
use Lenorix\DatadisClient\Exceptions\UnsupportedOperationException;
use Lenorix\DatadisClient\Guard\RepetitionGuard;
use Lenorix\DatadisClient\Guard\RequestFingerprinter;
use Lenorix\DatadisClient\Guard\RequestLedger;
use Lenorix\DatadisClient\Http\ApiCaller;
use Lenorix\DatadisClient\Http\Endpoint;
use Lenorix\DatadisClient\Support\InMemoryCache;
use Lenorix\DatadisClient\Support\PersonalDataRedactor;
use Lenorix\DatadisClient\Support\SystemClock;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Time\MonthPlanner;
use Lenorix\DatadisClient\Time\QuarterHourConvention;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\MeasurementType;
use Lenorix\DatadisClient\Values\Nif;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\SimpleCache\CacheInterface;
use SensitiveParameter;

/**
 * Client of the Datadis private API.
 *
 * Read docs/quirks-and-rules.md before building anything that repeats calls: Datadis refuses an
 * identical consumption, max power or reactive query for 24 hours, and a rejected request counts.
 * The only repeat is one new login and one new call after a 401, and only for a call that is safe
 * to repeat (the lists and the reads): a guarded query or a change (an authorization, unlinking a
 * user) is never sent twice, since Datadis may have acted on the rejected one. Nothing else that
 * may have been sent is retried.
 *
 * Every call that takes `$authorizedNif` refuses, before sending, one that differs from the
 * holder of a client made with forHolder() (InvalidRequestException). Reactive data and groups
 * exist only in API v2 (UnsupportedOperationException on v1).
 *
 * Every list method returns an ApiResult. An empty result is a normal answer, never zero
 * consumption, and `distributorErrors` carries partial failures reported inside a 200.
 */
final class DatadisClient
{
    private readonly ApiCaller $caller;

    private readonly RepetitionGuard $guard;

    /** Whether the ledger is this client's own, in memory, rather than one the application gave. */
    private readonly bool $ledgerInMemory;

    private readonly ClockInterface $clock;

    private readonly DateTimeZone $timeZone;

    /** The holder whose supplies this client reads (see forHolder()); null for the account's own. */
    private ?Nif $holder = null;

    /**
     * @param  ClientInterface|null  $http  any PSR-18 client; Guzzle is used when omitted
     * @param  CacheInterface|null  $tokenCache  any PSR-16 store to share the token between processes; it holds a live credential
     * @param  DateTimeZone|null  $timeZone  zone of the civil dates and times Datadis sends: Europe/Madrid by default, Atlantic/Canary for the Canary Islands
     * @param  RequestLedger|null  $ledger  where guarded queries attempted in the last 24 hours are remembered, so a repeat
     *                                      is refused locally. Without one, this client remembers its own in memory, which
     *                                      protects one process only: give a ledger on a shared store to cover several.
     */
    public function __construct(
        private readonly DatadisConfig $config,
        ?ClientInterface $http = null,
        private readonly ApiVersion $version = ApiVersion::V2,
        ?CacheInterface $tokenCache = null,
        ?ClockInterface $clock = null,
        ?DateTimeZone $timeZone = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?RequestLedger $ledger = null,
    ) {
        $this->clock = $clock ?? new SystemClock;
        $this->timeZone = $timeZone ?? new DateTimeZone(Month::SERVICE_TIME_ZONE);

        $this->caller = ApiCaller::connect($config, $http, $requestFactory, $streamFactory, $tokenCache, $this->clock);
        // Datadis refuses a repeated query for 24 hours and counts the refusal: never repeat one, even by default.
        $this->ledgerInMemory = $ledger === null;
        $ledger ??= new RequestLedger(new InMemoryCache($this->clock), new RequestFingerprinter(random_bytes(32)), $this->clock);
        $this->guard = new RepetitionGuard($ledger, $config->username());
    }

    /**
     * Builds a client from a plain array, as an application keeps its settings: every key of
     * DatadisConfig::fromArray() plus `api_version` (`v1` or `v2`, default `v2`) and `timezone`
     * (default `Europe/Madrid`). The collaborators an application provides (HTTP client, caches,
     * ledger) are passed as objects.
     *
     * @param  array<array-key, mixed>  $settings
     *
     * @throws ConfigurationException naming the setting that is missing or wrong
     */
    public static function fromArray(
        #[SensitiveParameter] array $settings,
        ?ClientInterface $http = null,
        ?CacheInterface $tokenCache = null,
        ?RequestLedger $ledger = null,
        ?ClockInterface $clock = null,
    ): self {
        $version = DatadisConfig::setting($settings, 'api_version');
        $zone = DatadisConfig::setting($settings, 'timezone');

        try {
            $timeZone = $zone === null ? null : new DateTimeZone($zone);
        } catch (Exception) {
            // Neither the value nor PHP's exception, which repeats it: a misplaced setting may hold a NIF.
            throw new ConfigurationException('The Datadis setting "timezone" is not a time zone.');
        }

        return new self(
            DatadisConfig::fromArray($settings),
            http: $http,
            version: $version === null ? ApiVersion::V2 : (ApiVersion::tryFrom(strtolower($version))
                ?? throw new ConfigurationException('The Datadis setting "api_version" must be "v1" or "v2".')),
            tokenCache: $tokenCache,
            clock: $clock,
            timeZone: $timeZone,
            ledger: $ledger,
        );
    }

    /**
     * Logs in, or takes the cached token, and tells until when the token lasts (null when Datadis
     * does not say): a check of the credentials that reads no data. `fresh` logs in now, whatever
     * the cache holds, so the username and password are tried; the new token replaces the cached
     * one, so clients sharing the token cache use it without logging in. A check that fails leaves
     * the cached token in place, and they keep using it until it expires or Datadis rejects it.
     *
     * @throws AuthenticationException when Datadis refuses the credentials
     * @throws DatadisException when the login fails otherwise; nothing is sent besides it
     */
    public function checkLogin(bool $fresh = false): ?DateTimeImmutable
    {
        return $this->caller->tokenExpiry($fresh);
    }

    /**
     * Refuses a range of months that Datadis would refuse: reversed, before the last 24 months or in
     * the future (Madrid calendar, this client's clock). The data calls check it too; this lets a
     * command or a form check it before it logs in.
     *
     * @throws InvalidRequestException when Datadis would refuse the range
     */
    public function assertServedRange(Month $startDate, ?Month $endDate = null): void
    {
        $this->assertRange($startDate, $endDate ?? $startDate);
    }

    /**
     * The same client, reading the supplies of a holder who authorized the account: the holder's NIF
     * goes as `authorizedNif` on every supply and data call, so no call can forget it. It shares the
     * login, the connection and the 24 hour guard with this client, which stays as it was.
     */
    public function forHolder(Nif $holder): self
    {
        $client = clone $this;
        $client->holder = $holder;

        return $client;
    }

    /**
     * The supply points of the account, or of the authorized third party.
     * Not subject to the 24 hour repetition rule.
     *
     * @return ApiResult<Supply>
     *
     * @throws InvalidRequestException when the distributor code or the holder is refused before sending
     * @throws DatadisException for a failure while talking to Datadis; check `requestSent`
     */
    public function getSupplies(?Nif $authorizedNif = null, ?string $distributorCode = null): ApiResult
    {
        if ($distributorCode !== null) {
            $this->assertDistributorCode($distributorCode);
        }

        $decoded = $this->fetchList(Endpoint::Supplies, ['authorizedNif' => $this->authorized($authorizedNif), 'distributorCode' => $distributorCode]);

        return Envelope::build($decoded, 'supplies', $this->name(Endpoint::Supplies), fn (array $row) => Supply::fromRow($row, $this->timeZone));
    }

    /**
     * The supply of a CUPS, resolved from the supplies list (see SupplyMatcher). Null when the
     * account has no such supply. Check isQueryable() before using its codes.
     *
     * @throws DatadisException as getSupplies()
     */
    public function findSupply(Cups $cups, ?Nif $authorizedNif = null): ?Supply
    {
        $result = $this->getSupplies($authorizedNif);
        $supply = SupplyMatcher::pick($result->records, $cups);

        // Not found while a distributor failed is not "not your supply": it may be behind that failure.
        if ($supply === null && $result->hasDistributorErrors()) {
            $reasons = PersonalDataRedactor::excerpt(implode('; ', array_map(static fn ($error) => (string) $error->errorDescription, $result->distributorErrors)));
            $endpoint = $this->name(Endpoint::Supplies);

            throw new ServiceUnavailableException("{$endpoint}: the supply was not found and a distributor failed: {$reasons}", 200, $reasons, $endpoint);
        }

        return $supply;
    }

    /**
     * The codes of the distributors that have supplies for the account. Codes are opaque strings.
     *
     * @return ApiResult<string>
     *
     * @throws InvalidRequestException when the holder is refused before sending
     * @throws DatadisException for a failure while talking to Datadis
     */
    public function getDistributorsWithSupplies(?Nif $authorizedNif = null): ApiResult
    {
        $decoded = $this->fetchList(Endpoint::Distributors, ['authorizedNif' => $this->authorized($authorizedNif)]);

        return DistributorCodes::result($decoded, $this->name(Endpoint::Distributors));
    }

    /**
     * Contract detail of one supply. Not subject to the 24 hour repetition rule.
     *
     * @return ApiResult<ContractDetail>
     *
     * @throws InvalidRequestException when the distributor code or the holder is refused before sending
     * @throws DatadisException for a failure while talking to Datadis
     */
    public function getContractDetail(Cups $cups, string $distributorCode, ?Nif $authorizedNif = null): ApiResult
    {
        $this->assertDistributorCode($distributorCode);

        $decoded = $this->fetch(Endpoint::ContractDetail, [
            'cups' => $cups->value(),
            'distributorCode' => $distributorCode,
            'authorizedNif' => $this->authorized($authorizedNif),
        ]);

        return Envelope::build($decoded, 'contract', $this->name(Endpoint::ContractDetail), fn (array $row) => ContractDetail::fromRow($row, $this->timeZone));
    }

    /**
     * Consumption between two whole months, both included (one month when `$endDate` is omitted). Datadis refuses the identical query for 24 hours.
     *
     * `$pointType` and `$distributorCode` come from the supply. Quarter-hourly data is only offered for
     * some point types; Datadis decides, so it is not checked here.
     *
     * @return ApiResult<ConsumptionReading>
     *
     * @throws InvalidRequestException when the query is refused before sending (nothing sent)
     * @throws RepetitionWindowException when the same query was attempted in the last 24 hours (`httpStatus` null: refused here, nothing sent)
     * @throws LedgerUnavailableException when the ledger's store fails (nothing sent)
     * @throws DatadisException for any other failure; check `requestSent`
     */
    public function getConsumptionData(
        Cups $cups,
        string $distributorCode,
        int $pointType,
        Month $startDate,
        ?Month $endDate = null,
        MeasurementType $measurementType = MeasurementType::Hourly,
        ?Nif $authorizedNif = null,
    ): ApiResult {
        $decoded = $this->fetch(Endpoint::Consumption, $this->consumptionQuery($cups, $distributorCode, $pointType, $startDate, $endDate, $measurementType, $authorizedNif));

        // Two quarter-hourly conventions are possible (unverified); the labels of the answer tell which.
        $quarters = $measurementType === MeasurementType::QuarterHourly ? QuarterHourConvention::detect(self::labels($decoded)) : null;

        // Rows keep their order, so the n-th row with the same date and time is its n-th occurrence.
        $seen = [];
        $decode = function (array $row) use (&$seen, $measurementType, $quarters): ?ConsumptionReading {
            $key = json_encode([$row['date'] ?? null, $row['time'] ?? null]);
            $occurrence = $seen[$key] = ($seen[$key] ?? -1) + 1;

            return ConsumptionReading::fromRow($row, $this->timeZone, $measurementType, $occurrence, $quarters);
        };

        return Envelope::build($decoded, 'timeCurve', $this->name(Endpoint::Consumption), $decode)->forMonths($startDate, $endDate ?? $startDate);
    }

    /**
     * Maximum power between two whole months, both included (one month when `$endDate` is omitted). Datadis refuses the identical query for 24 hours.
     *
     * @return ApiResult<MaxPowerReading>
     *
     * @throws InvalidRequestException when the query is refused before sending (nothing sent)
     * @throws RepetitionWindowException when the same query was attempted in the last 24 hours (`httpStatus` null: refused here, nothing sent)
     * @throws LedgerUnavailableException when the ledger's store fails (nothing sent)
     * @throws DatadisException for any other failure; check `requestSent`
     */
    public function getMaxPower(Cups $cups, string $distributorCode, Month $startDate, ?Month $endDate = null, ?Nif $authorizedNif = null): ApiResult
    {
        $decoded = $this->fetch(Endpoint::MaxPower, $this->powerQuery($cups, $distributorCode, $startDate, $endDate, $authorizedNif));

        return Envelope::build($decoded, 'maxPower', $this->name(Endpoint::MaxPower), fn (array $row) => MaxPowerReading::fromRow($row, $this->timeZone))->forMonths($startDate, $endDate ?? $startDate);
    }

    /**
     * Reactive energy between two whole months, both included (v2 only). Datadis refuses the identical query for 24 hours.
     * The result usually holds zero or one ReactiveEnergy.
     *
     * @return ApiResult<ReactiveEnergy>
     *
     * @throws InvalidRequestException when the query is refused before sending (nothing sent)
     * @throws RepetitionWindowException when the same query was attempted in the last 24 hours (`httpStatus` null: refused here, nothing sent)
     * @throws LedgerUnavailableException when the ledger's store fails (nothing sent)
     * @throws DatadisException for any other failure; check `requestSent`
     * @throws UnsupportedOperationException on API v1 (nothing sent)
     */
    public function getReactiveData(Cups $cups, string $distributorCode, Month $startDate, ?Month $endDate = null, ?Nif $authorizedNif = null): ApiResult
    {
        $this->assertReactive();

        $decoded = $this->fetch(Endpoint::Reactive, $this->powerQuery($cups, $distributorCode, $startDate, $endDate, $authorizedNif));

        return ReactiveEnergyAnswer::result($decoded, $this->name(Endpoint::Reactive))->forMonths($startDate, $endDate ?? $startDate);
    }

    /**
     * Records that this consumption query was sent at `$sentAt`, before this client kept the record:
     * for the moment an application switches from a record of its own to the ledger. Give the query
     * as it was sent; it is built exactly as getConsumptionData() builds it (the holder and the
     * account's own NIF included), so the ledger refuses that very query until its window ends. An
     * attempt older than the window is not recorded, and the newest attempt of a query wins. Import
     * before any worker sends with the ledger, with the workers paused, through a client given the
     * ledger the workers share.
     *
     * @return bool whether it was recorded
     *
     * @throws ConfigurationException when the client has no ledger given by the application, only its own in memory
     * @throws InvalidRequestException when a value is not valid, the range is reversed or `$sentAt` is in the future
     * @throws LedgerUnavailableException when the ledger's store fails
     */
    public function rememberConsumptionData(
        DateTimeInterface $sentAt,
        Cups $cups,
        string $distributorCode,
        int $pointType,
        Month $startDate,
        ?Month $endDate = null,
        MeasurementType $measurementType = MeasurementType::Hourly,
        ?Nif $authorizedNif = null,
    ): bool {
        return $this->remember(Endpoint::Consumption, $this->consumptionQuery($cups, $distributorCode, $pointType, $startDate, $endDate, $measurementType, $authorizedNif, served: false), $sentAt);
    }

    /**
     * Records that this maximum power query was sent at `$sentAt`. See rememberConsumptionData().
     * Datadis keys it like a reactive query with the same parameters, without `authorizedNif`.
     *
     * @return bool whether it was recorded
     *
     * @throws ConfigurationException when the client has no ledger given by the application
     * @throws InvalidRequestException when a value is not valid, the range is reversed or `$sentAt` is in the future
     * @throws LedgerUnavailableException when the ledger's store fails
     */
    public function rememberMaxPower(DateTimeInterface $sentAt, Cups $cups, string $distributorCode, Month $startDate, ?Month $endDate = null, ?Nif $authorizedNif = null): bool
    {
        return $this->remember(Endpoint::MaxPower, $this->powerQuery($cups, $distributorCode, $startDate, $endDate, $authorizedNif, served: false), $sentAt);
    }

    /**
     * Records that this reactive query was sent at `$sentAt`: the same key as maximum power. See
     * rememberConsumptionData().
     *
     * @return bool whether it was recorded
     *
     * @throws ConfigurationException when the client has no ledger given by the application
     * @throws InvalidRequestException when a value is not valid, the range is reversed or `$sentAt` is in the future
     * @throws LedgerUnavailableException when the ledger's store fails
     */
    public function rememberReactiveData(DateTimeInterface $sentAt, Cups $cups, string $distributorCode, Month $startDate, ?Month $endDate = null, ?Nif $authorizedNif = null): bool
    {
        $this->assertReactive();

        return $this->remember(Endpoint::Reactive, $this->powerQuery($cups, $distributorCode, $startDate, $endDate, $authorizedNif, served: false), $sentAt);
    }

    /**
     * rememberConsumptionData() for a supply as listed by getSupplies().
     *
     * @throws ConfigurationException when the client has no ledger given by the application
     * @throws InvalidRequestException when the supply or the range cannot be queried, or `$sentAt` is in the future
     * @throws LedgerUnavailableException when the ledger's store fails
     */
    public function rememberConsumptionDataOf(
        DateTimeInterface $sentAt,
        #[SensitiveParameter] Supply $supply,
        Month $startDate,
        ?Month $endDate = null,
        MeasurementType $measurementType = MeasurementType::Hourly,
        ?Nif $authorizedNif = null,
    ): bool {
        [$cups, $code] = $this->queryable($supply, $startDate, $endDate);

        return $this->rememberConsumptionData($sentAt, $cups, $code, $this->pointTypeOf($supply), $startDate, $endDate, $measurementType, $authorizedNif);
    }

    /**
     * rememberMaxPower() for a supply as listed by getSupplies().
     *
     * @throws ConfigurationException when the client has no ledger given by the application
     * @throws InvalidRequestException when the supply or the range cannot be queried, or `$sentAt` is in the future
     * @throws LedgerUnavailableException when the ledger's store fails
     */
    public function rememberMaxPowerOf(DateTimeInterface $sentAt, #[SensitiveParameter] Supply $supply, Month $startDate, ?Month $endDate = null, ?Nif $authorizedNif = null): bool
    {
        [$cups, $code] = $this->queryable($supply, $startDate, $endDate);

        return $this->rememberMaxPower($sentAt, $cups, $code, $startDate, $endDate, $authorizedNif);
    }

    /**
     * rememberReactiveData() for a supply as listed by getSupplies().
     *
     * @throws ConfigurationException when the client has no ledger given by the application
     * @throws InvalidRequestException when the supply or the range cannot be queried, or `$sentAt` is in the future
     * @throws LedgerUnavailableException when the ledger's store fails
     */
    public function rememberReactiveDataOf(DateTimeInterface $sentAt, #[SensitiveParameter] Supply $supply, Month $startDate, ?Month $endDate = null, ?Nif $authorizedNif = null): bool
    {
        [$cups, $code] = $this->queryable($supply, $startDate, $endDate);

        return $this->rememberReactiveData($sentAt, $cups, $code, $startDate, $endDate, $authorizedNif);
    }

    /**
     * Until when getConsumptionData() with these arguments would be refused by the ledger, without
     * sending or claiming anything: null when it may be sent now. For a command that only looks; a
     * job calls and catches the RepetitionWindowException instead, which decides in one step.
     *
     * @throws InvalidRequestException when the query could not be sent anyway
     * @throws LedgerUnavailableException when the ledger's store cannot be read
     */
    public function consumptionDataBlockedUntil(
        Cups $cups,
        string $distributorCode,
        int $pointType,
        Month $startDate,
        ?Month $endDate = null,
        MeasurementType $measurementType = MeasurementType::Hourly,
        ?Nif $authorizedNif = null,
    ): ?DateTimeImmutable {
        return $this->guard->blockedUntil(Endpoint::Consumption, $this->consumptionQuery($cups, $distributorCode, $pointType, $startDate, $endDate, $measurementType, $authorizedNif));
    }

    /**
     * Until when getMaxPower() with these arguments would be refused. See consumptionDataBlockedUntil().
     *
     * @throws InvalidRequestException when the query could not be sent anyway
     * @throws LedgerUnavailableException when the ledger's store cannot be read
     */
    public function maxPowerBlockedUntil(Cups $cups, string $distributorCode, Month $startDate, ?Month $endDate = null, ?Nif $authorizedNif = null): ?DateTimeImmutable
    {
        return $this->guard->blockedUntil(Endpoint::MaxPower, $this->powerQuery($cups, $distributorCode, $startDate, $endDate, $authorizedNif));
    }

    /**
     * Until when getReactiveData() with these arguments would be refused: the same key as maximum
     * power. See consumptionDataBlockedUntil().
     *
     * @throws InvalidRequestException when the query could not be sent anyway
     * @throws LedgerUnavailableException when the ledger's store cannot be read
     */
    public function reactiveDataBlockedUntil(Cups $cups, string $distributorCode, Month $startDate, ?Month $endDate = null, ?Nif $authorizedNif = null): ?DateTimeImmutable
    {
        $this->assertReactive();

        return $this->guard->blockedUntil(Endpoint::Reactive, $this->powerQuery($cups, $distributorCode, $startDate, $endDate, $authorizedNif));
    }

    /**
     * consumptionDataBlockedUntil() for a supply as listed by getSupplies().
     *
     * @throws InvalidRequestException when the supply or the range cannot be queried
     * @throws LedgerUnavailableException when the ledger's store cannot be read
     */
    public function consumptionDataOfBlockedUntil(
        #[SensitiveParameter] Supply $supply,
        Month $startDate,
        ?Month $endDate = null,
        MeasurementType $measurementType = MeasurementType::Hourly,
        ?Nif $authorizedNif = null,
    ): ?DateTimeImmutable {
        [$cups, $code] = $this->queryable($supply, $startDate, $endDate);

        return $this->consumptionDataBlockedUntil($cups, $code, $this->pointTypeOf($supply), $startDate, $endDate, $measurementType, $authorizedNif);
    }

    /**
     * maxPowerBlockedUntil() for a supply as listed by getSupplies().
     *
     * @throws InvalidRequestException when the supply or the range cannot be queried
     * @throws LedgerUnavailableException when the ledger's store cannot be read
     */
    public function maxPowerOfBlockedUntil(#[SensitiveParameter] Supply $supply, Month $startDate, ?Month $endDate = null, ?Nif $authorizedNif = null): ?DateTimeImmutable
    {
        [$cups, $code] = $this->queryable($supply, $startDate, $endDate);

        return $this->maxPowerBlockedUntil($cups, $code, $startDate, $endDate, $authorizedNif);
    }

    /**
     * reactiveDataBlockedUntil() for a supply as listed by getSupplies().
     *
     * @throws InvalidRequestException when the supply or the range cannot be queried
     * @throws LedgerUnavailableException when the ledger's store cannot be read
     */
    public function reactiveDataOfBlockedUntil(#[SensitiveParameter] Supply $supply, Month $startDate, ?Month $endDate = null, ?Nif $authorizedNif = null): ?DateTimeImmutable
    {
        [$cups, $code] = $this->queryable($supply, $startDate, $endDate);

        return $this->reactiveDataBlockedUntil($cups, $code, $startDate, $endDate, $authorizedNif);
    }

    /** @param  array<string, string|int|null>  $query */
    private function remember(Endpoint $endpoint, #[SensitiveParameter] array $query, DateTimeInterface $sentAt): bool
    {
        // Remembered in this process's memory only, no worker would ever see it: an import would report success and protect nothing.
        if ($this->ledgerInMemory) {
            throw new ConfigurationException('This client has no ledger of yours, only its own in memory: give it the RequestLedger your workers share before remembering what was sent.');
        }

        return $this->guard->remember($endpoint, $this->name($endpoint), $query, $sentAt);
    }

    /**
     * getContractDetail() for a supply as listed by getSupplies().
     *
     * @return ApiResult<ContractDetail>
     *
     * @throws InvalidRequestException when the supply was listed without a usable CUPS or distributor code
     * @throws DatadisException as getContractDetail()
     */
    public function getContractDetailOf(#[SensitiveParameter] Supply $supply, ?Nif $authorizedNif = null): ApiResult
    {
        [$cups, $code] = $this->queryable($supply);

        return $this->getContractDetail($cups, $code, $authorizedNif);
    }

    /**
     * getConsumptionData() for a supply as listed by getSupplies().
     *
     * @return ApiResult<ConsumptionReading>
     *
     * @throws OutOfContractRangeException when the range falls outside the supply's contract (nothing sent)
     * @throws InvalidRequestException when the supply cannot be queried (nothing sent)
     * @throws DatadisException as getConsumptionData()
     */
    public function getConsumptionDataOf(
        #[SensitiveParameter] Supply $supply,
        Month $startDate,
        ?Month $endDate = null,
        MeasurementType $measurementType = MeasurementType::Hourly,
        ?Nif $authorizedNif = null,
    ): ApiResult {
        [$cups, $code] = $this->queryable($supply, $startDate, $endDate);
        $pointType = $this->pointTypeOf($supply);

        return $this->getConsumptionData($cups, $code, $pointType, $startDate, $endDate, $measurementType, $authorizedNif);
    }

    /**
     * getMaxPower() for a supply as listed by getSupplies().
     *
     * @return ApiResult<MaxPowerReading>
     *
     * @throws OutOfContractRangeException when the range falls outside the supply's contract (nothing sent)
     * @throws InvalidRequestException when the supply cannot be queried (nothing sent)
     * @throws DatadisException as getMaxPower()
     */
    public function getMaxPowerOf(#[SensitiveParameter] Supply $supply, Month $startDate, ?Month $endDate = null, ?Nif $authorizedNif = null): ApiResult
    {
        [$cups, $code] = $this->queryable($supply, $startDate, $endDate);

        return $this->getMaxPower($cups, $code, $startDate, $endDate, $authorizedNif);
    }

    /**
     * getReactiveData() for a supply as listed by getSupplies().
     *
     * @return ApiResult<ReactiveEnergy>
     *
     * @throws OutOfContractRangeException when the range falls outside the supply's contract (nothing sent)
     * @throws InvalidRequestException when the supply cannot be queried (nothing sent)
     * @throws DatadisException as getReactiveData()
     */
    public function getReactiveDataOf(#[SensitiveParameter] Supply $supply, Month $startDate, ?Month $endDate = null, ?Nif $authorizedNif = null): ApiResult
    {
        [$cups, $code] = $this->queryable($supply, $startDate, $endDate);

        return $this->getReactiveData($cups, $code, $startDate, $endDate, $authorizedNif);
    }

    /**
     * The consumption of the current month for a sync that runs every day: the range comes from
     * MonthPlanner::latest(), so today's query is never yesterday's, and on odd days it also
     * brings the previous month. The result's `startDate` and `endDate` say which months it asked
     * for, so a month that came back empty is known to have been asked. A second run on the same day is refused like any repeat.
     * Schedule the job at a fixed hour in Madrid time (the range follows the Madrid calendar day),
     * well clear of midnight and of 02:00-03:00; split the records by month before adding them up.
     *
     * @return ApiResult<ConsumptionReading>
     *
     * @throws NothingToRefreshException when the supply's contract has nothing to refresh this month
     * @throws RepetitionWindowException when today's range was asked in the last 24 hours (its months in `startDate`, `endDate`)
     * @throws DatadisException as getConsumptionDataOf()
     */
    public function getLatestConsumptionDataOf(
        #[SensitiveParameter] Supply $supply,
        MeasurementType $measurementType = MeasurementType::Hourly,
        ?Nif $authorizedNif = null,
    ): ApiResult {
        [$from, $to] = $this->latest($supply);

        return $this->getConsumptionDataOf($supply, $from, $to, $measurementType, $authorizedNif);
    }

    /**
     * The maximum power of the current month for a sync that runs every day. See
     * getLatestConsumptionDataOf(). Reactive data shares its 24 hour key with maximum power, so
     * there is no such shortcut for it: ask reactive data for closed months.
     *
     * @return ApiResult<MaxPowerReading>
     *
     * @throws NothingToRefreshException when the supply's contract has nothing to refresh this month
     * @throws RepetitionWindowException when today's range was asked in the last 24 hours (its months in `startDate`, `endDate`)
     * @throws DatadisException as getMaxPowerOf()
     */
    public function getLatestMaxPowerOf(#[SensitiveParameter] Supply $supply, ?Nif $authorizedNif = null): ApiResult
    {
        [$from, $to] = $this->latest($supply);

        return $this->getMaxPowerOf($supply, $from, $to, $authorizedNif);
    }

    /** @return array{0: Month, 1: Month} */
    private function latest(#[SensitiveParameter] Supply $supply): array
    {
        return MonthPlanner::latest($this->clock->now(), $supply)[0]
            ?? throw new NothingToRefreshException('The contract of the supply has no data to refresh this month.');
    }

    /**
     * Authorizes a third party to read the account's supplies (all of them when no CUPS is given).
     *
     * This endpoint exists only in v1 and is used whatever the configured version. UNVERIFIED: it
     * comes from the manual only; the date format (assumed `YYYY/MM/DD`) and the way the list of
     * CUPS is sent (the key repeated per CUPS) are not documented. Returns the raw answer text.
     *
     * @throws InvalidRequestException for the account itself, a reversed period or a CUPS listed twice (nothing sent)
     * @throws DatadisException for a failure while talking to Datadis; check `requestSent` before making it again
     */
    public function newAuthorization(
        Nif $authorizedNif,
        ?DateTimeInterface $startDate = null,
        ?DateTimeInterface $endDate = null,
        Cups ...$cups,
    ): string {
        $this->assertThirdParty($authorizedNif);

        if ($startDate !== null && $endDate !== null && $startDate->format('Y-m-d') > $endDate->format('Y-m-d')) {
            throw new InvalidRequestException('The authorization must not end before it starts.');
        }

        return $this->fetchText(Endpoint::NewAuthorization, [
            'authorizedNif' => $authorizedNif->value(),
            'startDate' => $startDate?->format('Y/m/d'),
            'endDate' => $endDate?->format('Y/m/d'),
            'cups' => $this->cupsList($cups),
        ]);
    }

    /**
     * Cancels a third party's authorization (for every supply when no CUPS is given).
     * v1 only and UNVERIFIED, like newAuthorization(). Returns the raw answer text.
     *
     * @throws InvalidRequestException for the account itself or a CUPS listed twice (nothing sent)
     * @throws DatadisException for a failure while talking to Datadis; check `requestSent` before making it again
     */
    public function cancelAuthorization(Nif $authorizedNif, Cups ...$cups): string
    {
        $this->assertThirdParty($authorizedNif);

        return $this->fetchText(Endpoint::CancelAuthorization, [
            'authorizedNif' => $authorizedNif->value(),
            'cups' => $this->cupsList($cups),
        ]);
    }

    /**
     * The authorizations of the account, or of the given owner. v1 only and UNVERIFIED.
     *
     * @return ApiResult<Authorization>
     */
    public function listAuthorization(?Nif $ownerNif = null): ApiResult
    {
        $decoded = $this->fetch(Endpoint::Authorizations, ['ownerNif' => $ownerNif?->value()]);

        return Envelope::build($decoded, 'authorizations', $this->name(Endpoint::Authorizations), fn (array $row) => Authorization::fromRow($row, $this->timeZone));
    }

    /**
     * The supply groups defined in the account (v2 only). An account without groups gets the text
     * `No groups`, labelled JSON (verified), which is an empty result.
     *
     * @return ApiResult<Group>
     *
     * @throws UnsupportedOperationException on API v1 (nothing sent)
     * @throws DatadisException for a failure while talking to Datadis
     */
    public function getGroups(): ApiResult
    {
        if ($this->version !== ApiVersion::V2) {
            throw new UnsupportedOperationException('Groups exist only in API v2.');
        }

        try {
            $decoded = $this->fetch(Endpoint::Groups, []);
        } catch (UninterpretableResponseException $e) {
            if (trim((string) $e->detail) === 'No groups') {
                return new ApiResult([]);
            }

            throw $e;
        }

        return Envelope::build($decoded, 'groups', $this->name(Endpoint::Groups), static fn (array $row) => Group::fromRow($row));
    }

    /**
     * The users linked to the partner account (Datadis partner programme), verified against a real
     * answer.
     *
     * @return ApiResult<PartnerUser>
     */
    public function partnerUserList(): ApiResult
    {
        $decoded = $this->fetch(Endpoint::PartnerUsers, []);

        return Envelope::build($decoded, 'users', $this->name(Endpoint::PartnerUsers), fn (array $row) => PartnerUser::fromRow($row, $this->timeZone));
    }

    /**
     * Unlinks a user from the partner account. It changes data, so it is never retried
     * automatically. UNVERIFIED: returns the raw answer text.
     */
    public function partnerDeleteUser(Nif $nif): string
    {
        return $this->fetchText(Endpoint::PartnerDeleteUser, ['nif' => $nif->value()]);
    }

    /**
     * The date the partner agreement started, as Datadis writes it, or null when there is none
     * (`{"partnerAgreementDate": null}`, verified). The format of a date that is set has not been
     * seen. `$nif` is only for callers allowed to consult another partner.
     */
    public function partnerAgreementDate(?Nif $nif = null): ?string
    {
        $decoded = $this->fetch(Endpoint::PartnerAgreementDate, ['nif' => $nif?->value()]);

        return Fields::nonEmptyText($decoded, 'partnerAgreementDate');
    }

    /**
     * For the account lists: an account without supplies gets a 404 "No supplies" (verified), which
     * is an empty list, not a failure.
     *
     * @param  array<string, string|int|list<string>|null>  $query
     * @return array<array-key, mixed>
     */
    private function fetchList(Endpoint $endpoint, #[SensitiveParameter] array $query): array
    {
        try {
            return $this->fetch($endpoint, $query);
        } catch (NoDataException $e) {
            if ($e->httpStatus === 404) {
                return [];
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, string|int|list<string>|null>  $query
     * @return array<array-key, mixed>
     */
    private function fetch(Endpoint $endpoint, #[SensitiveParameter] array $query): array
    {
        $name = $this->name($endpoint);
        $send = fn (): array => $this->caller->get($endpoint->path($this->version), $query, $name, sendAgainAfter401: $endpoint->isSafeToRepeat());

        return $this->guard->call($endpoint, $name, $query, $send);
    }

    /** @param array<string, string|int|list<string>|null> $query */
    private function fetchText(Endpoint $endpoint, #[SensitiveParameter] array $query): string
    {
        // A call that changes data is never sent twice, not even after a rejected token.
        return $this->caller->getText($endpoint->path($this->version), $query, $this->name($endpoint), sendAgainAfter401: $endpoint->isSafeToRepeat());
    }

    /** The endpoint as it appears in the path and in exceptions. */
    private function name(Endpoint $endpoint): string
    {
        return $endpoint->name($this->version);
    }

    /** authorizedNif is only for a third party's supplies: for the account itself it must be omitted. */
    private function authorized(?Nif $nif): ?string
    {
        if ($nif !== null && $this->holder !== null && ! $nif->equals($this->holder)) {
            throw new InvalidRequestException('This client reads the supplies of one holder; use forHolder() for another one.');
        }

        $nif ??= $this->holder;

        return $nif === null || $nif->value() === $this->config->username() ? null : $nif->value();
    }

    private function assertThirdParty(Nif $nif): void
    {
        if ($nif->value() === $this->config->username()) {
            throw new InvalidRequestException('An authorization is for a third party, not for the account itself.');
        }
    }

    /**
     * @param  array<Cups>  $cups
     * @return list<string>
     */
    private function cupsList(array $cups): array
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
    private function queryable(#[SensitiveParameter] Supply $supply, ?Month $startDate = null, ?Month $endDate = null): array
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
    private function pointTypeOf(#[SensitiveParameter] Supply $supply): int
    {
        if ($supply->pointType === null || ! Supply::isValidPointType($supply->pointType)) {
            throw new InvalidRequestException('The supply was listed without a usable point type, which consumption needs; list the supplies again.');
        }

        return $supply->pointType;
    }

    /**
     * The time labels of a consumption answer, in either version's shape.
     *
     * @param  array<array-key, mixed>  $decoded
     * @return list<string>
     */
    private static function labels(#[SensitiveParameter] array $decoded): array
    {
        $rows = array_is_list($decoded) ? $decoded : ($decoded['timeCurve'] ?? []);
        $labels = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && is_string($row['time'] ?? null)) {
                $labels[] = $row['time'];
            }
        }

        return $labels;
    }

    /**
     * The consumption query exactly as it is sent, and remembered: one place, so both agree.
     *
     * @return array<string, string|int|null>
     */
    private function consumptionQuery(
        Cups $cups,
        string $distributorCode,
        int $pointType,
        Month $startDate,
        ?Month $endDate,
        MeasurementType $measurementType,
        ?Nif $authorizedNif,
        bool $served = true,
    ): array {
        $query = $this->monthQuery($cups, $distributorCode, $startDate, $endDate, $served);
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
    private function powerQuery(Cups $cups, string $distributorCode, Month $startDate, ?Month $endDate, ?Nif $authorizedNif, bool $served = true): array
    {
        return $this->monthQuery($cups, $distributorCode, $startDate, $endDate, $served) + ['authorizedNif' => $this->authorized($authorizedNif)];
    }

    /**
     * The part every month-range data call shares, after checking it: one month when there is no end.
     *
     * @return array{cups: string, distributorCode: string, startDate: string, endDate: string}
     */
    private function monthQuery(Cups $cups, string $distributorCode, Month $startDate, ?Month $endDate, bool $served = true): array
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
    private function assertReactive(): void
    {
        if ($this->version !== ApiVersion::V2) {
            throw new UnsupportedOperationException('Reactive data exists only in API v2.');
        }
    }

    private function assertDistributorCode(string $code): void
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
    private function assertRange(Month $startDate, Month $endDate, bool $served = true): void
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
