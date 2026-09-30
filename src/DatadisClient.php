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
use Lenorix\DatadisClient\Data\ReactiveEnergy;
use Lenorix\DatadisClient\Data\Supply;
use Lenorix\DatadisClient\Decoding\DistributorCodes;
use Lenorix\DatadisClient\Decoding\Envelope;
use Lenorix\DatadisClient\Decoding\ReactiveEnergyAnswer;
use Lenorix\DatadisClient\Exceptions\ConfigurationException;
use Lenorix\DatadisClient\Exceptions\InvalidRequestException;
use Lenorix\DatadisClient\Exceptions\NoDataException;
use Lenorix\DatadisClient\Exceptions\ServiceUnavailableException;
use Lenorix\DatadisClient\Exceptions\UnsupportedOperationException;
use Lenorix\DatadisClient\Guard\RepetitionGuard;
use Lenorix\DatadisClient\Guard\RequestLedger;
use Lenorix\DatadisClient\Http\ApiCaller;
use Lenorix\DatadisClient\Http\Connection;
use Lenorix\DatadisClient\Http\Endpoint;
use Lenorix\DatadisClient\Support\SystemClock;
use Lenorix\DatadisClient\Time\Month;
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
 * The only repeat is one new login and one new call after a 401 (the token was refused, so the
 * request was not served); nothing else that may have been sent is retried.
 *
 * Every list method returns an ApiResult. An empty result is a normal answer, never zero
 * consumption, and `distributorErrors` carries partial failures reported inside a 200.
 */
final class DatadisClient
{
    private readonly ApiCaller $caller;

    private readonly ?RepetitionGuard $guard;

    private readonly ClockInterface $clock;

    private readonly DateTimeZone $timeZone;

    /** The holder whose supplies this client reads (see forHolder()); null for the account's own. */
    private ?Nif $holder = null;

    /**
     * @param  ClientInterface|null  $http  any PSR-18 client; Guzzle is used when omitted
     * @param  CacheInterface|null  $tokenCache  any PSR-16 store to share the token between processes; it holds a live credential
     * @param  DateTimeZone|null  $timeZone  zone of the civil dates and times Datadis sends: Europe/Madrid by default, Atlantic/Canary for the Canary Islands
     * @param  RequestLedger|null  $ledger  when given, a guarded query already attempted in the last 24 hours is refused locally
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

        $this->caller = (new Connection($config, $http, $requestFactory, $streamFactory))->caller($config, $tokenCache, $this->clock);
        $this->guard = $ledger === null ? null : new RepetitionGuard($ledger, $config->username);
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
        } catch (Exception $e) {
            throw new ConfigurationException("The Datadis setting \"timezone\" is not a time zone: {$zone}.", $e);
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
     */
    public function findSupply(Cups $cups, ?Nif $authorizedNif = null): ?Supply
    {
        $result = $this->getSupplies($authorizedNif);
        $supply = SupplyMatcher::pick($result->records, $cups);

        // Not found while a distributor failed is not "not your supply": it may be behind that failure.
        if ($supply === null && $result->hasDistributorErrors()) {
            $reasons = implode('; ', array_map(static fn ($error) => (string) $error->errorDescription, $result->distributorErrors));
            $endpoint = $this->name(Endpoint::Supplies);

            throw new ServiceUnavailableException("{$endpoint}: the supply was not found and a distributor failed: {$reasons}", 200, $reasons, $endpoint);
        }

        return $supply;
    }

    /**
     * The codes of the distributors that have supplies for the account. Codes are opaque strings.
     *
     * @return ApiResult<string>
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
     * Consumption between two whole months, both included (one month when `$to` is omitted). Datadis refuses the identical query for 24 hours.
     *
     * `$pointType` and `$distributorCode` come from the supply. Quarter-hourly data is only offered for
     * some point types; Datadis decides, so it is not checked here.
     *
     * @return ApiResult<ConsumptionReading>
     */
    public function getConsumptionData(
        Cups $cups,
        string $distributorCode,
        int $pointType,
        Month $from,
        ?Month $to = null,
        MeasurementType $measurementType = MeasurementType::Hourly,
        ?Nif $authorizedNif = null,
    ): ApiResult {
        $to ??= $from;
        $this->assertDistributorCode($distributorCode);
        $this->assertPointType($pointType);
        $this->assertRange($from, $to);

        $decoded = $this->fetch(Endpoint::Consumption, [
            'cups' => $cups->value(),
            'distributorCode' => $distributorCode,
            'startDate' => $from->format(),
            'endDate' => $to->format(),
            'measurementType' => $measurementType->value,
            'pointType' => $pointType,
            'authorizedNif' => $this->authorized($authorizedNif),
        ]);

        // Rows keep their order, so the n-th row with the same date and time is its n-th occurrence.
        $seen = [];
        $decode = function (array $row) use (&$seen, $measurementType): ?ConsumptionReading {
            $key = json_encode([$row['date'] ?? null, $row['time'] ?? null]);
            $occurrence = $seen[$key] = ($seen[$key] ?? -1) + 1;

            return ConsumptionReading::fromRow($row, $this->timeZone, $measurementType, $occurrence);
        };

        return Envelope::build($decoded, 'timeCurve', $this->name(Endpoint::Consumption), $decode);
    }

    /**
     * Maximum power between two whole months, both included (one month when `$to` is omitted). Datadis refuses the identical query for 24 hours.
     *
     * @return ApiResult<MaxPowerReading>
     */
    public function getMaxPower(Cups $cups, string $distributorCode, Month $from, ?Month $to = null, ?Nif $authorizedNif = null): ApiResult
    {
        $to ??= $from;
        $this->assertDistributorCode($distributorCode);
        $this->assertRange($from, $to);

        $decoded = $this->fetch(Endpoint::MaxPower, [
            'cups' => $cups->value(),
            'distributorCode' => $distributorCode,
            'startDate' => $from->format(),
            'endDate' => $to->format(),
            'authorizedNif' => $this->authorized($authorizedNif),
        ]);

        return Envelope::build($decoded, 'maxPower', $this->name(Endpoint::MaxPower), fn (array $row) => MaxPowerReading::fromRow($row, $this->timeZone));
    }

    /**
     * Reactive energy between two whole months, both included (v2 only). Datadis refuses the identical query for 24 hours.
     * The result usually holds zero or one ReactiveEnergy.
     *
     * @return ApiResult<ReactiveEnergy>
     */
    public function getReactiveData(Cups $cups, string $distributorCode, Month $from, ?Month $to = null, ?Nif $authorizedNif = null): ApiResult
    {
        $to ??= $from;

        if ($this->version !== ApiVersion::V2) {
            throw new UnsupportedOperationException('Reactive data exists only in API v2.');
        }

        $this->assertDistributorCode($distributorCode);
        $this->assertRange($from, $to);

        $decoded = $this->fetch(Endpoint::Reactive, [
            'cups' => $cups->value(),
            'distributorCode' => $distributorCode,
            'startDate' => $from->format(),
            'endDate' => $to->format(),
            'authorizedNif' => $this->authorized($authorizedNif),
        ]);

        return ReactiveEnergyAnswer::result($decoded, $this->name(Endpoint::Reactive));
    }

    /**
     * getContractDetail() for a supply as listed by getSupplies().
     *
     * @return ApiResult<ContractDetail>
     */
    public function getContractDetailOf(Supply $supply, ?Nif $authorizedNif = null): ApiResult
    {
        [$cups, $code] = $this->queryable($supply);

        return $this->getContractDetail($cups, $code, $authorizedNif);
    }

    /**
     * getConsumptionData() for a supply as listed by getSupplies().
     *
     * @return ApiResult<ConsumptionReading>
     */
    public function getConsumptionDataOf(
        Supply $supply,
        Month $from,
        ?Month $to = null,
        MeasurementType $measurementType = MeasurementType::Hourly,
        ?Nif $authorizedNif = null,
    ): ApiResult {
        [$cups, $code, $pointType] = $this->queryable($supply);

        return $this->getConsumptionData($cups, $code, $pointType, $from, $to, $measurementType, $authorizedNif);
    }

    /**
     * getMaxPower() for a supply as listed by getSupplies().
     *
     * @return ApiResult<MaxPowerReading>
     */
    public function getMaxPowerOf(Supply $supply, Month $from, ?Month $to = null, ?Nif $authorizedNif = null): ApiResult
    {
        [$cups, $code] = $this->queryable($supply);

        return $this->getMaxPower($cups, $code, $from, $to, $authorizedNif);
    }

    /**
     * getReactiveData() for a supply as listed by getSupplies().
     *
     * @return ApiResult<ReactiveEnergy>
     */
    public function getReactiveDataOf(Supply $supply, Month $from, ?Month $to = null, ?Nif $authorizedNif = null): ApiResult
    {
        [$cups, $code] = $this->queryable($supply);

        return $this->getReactiveData($cups, $code, $from, $to, $authorizedNif);
    }

    /**
     * Authorizes a third party to read the account's supplies (all of them when no CUPS is given).
     *
     * This endpoint exists only in v1 and is used whatever the configured version. UNVERIFIED: it
     * comes from the manual only; the date format (assumed `YYYY/MM/DD`) and the way the list of
     * CUPS is sent (the key repeated per CUPS) are not documented. Returns the raw answer text.
     */
    public function newAuthorization(
        Nif $authorizedNif,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $to = null,
        Cups ...$cups,
    ): string {
        $this->assertThirdParty($authorizedNif);

        if ($from !== null && $to !== null && $from->format('Y-m-d') > $to->format('Y-m-d')) {
            throw new InvalidRequestException('The authorization must not end before it starts.');
        }

        return $this->fetchText(Endpoint::NewAuthorization, [
            'authorizedNif' => $authorizedNif->value(),
            'startDate' => $from?->format('Y/m/d'),
            'endDate' => $to?->format('Y/m/d'),
            'cups' => $this->cupsList($cups),
        ]);
    }

    /**
     * Cancels a third party's authorization (for every supply when no CUPS is given).
     * v1 only and UNVERIFIED, like newAuthorization(). Returns the raw answer text.
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
     * The supply groups defined in the account (v2 only).
     *
     * @return ApiResult<Group>
     */
    public function getGroups(): ApiResult
    {
        if ($this->version !== ApiVersion::V2) {
            throw new UnsupportedOperationException('Groups exist only in API v2.');
        }

        $decoded = $this->fetch(Endpoint::Groups, []);

        return Envelope::build($decoded, 'groups', $this->name(Endpoint::Groups), static fn (array $row) => Group::fromRow($row));
    }

    /**
     * The users linked to the partner account (Datadis partner programme). UNVERIFIED: the official
     * documentation does not describe the answer, so the decoded JSON is returned as is.
     *
     * @return array<array-key, mixed>
     */
    public function partnerUserList(): array
    {
        return $this->fetch(Endpoint::PartnerUsers, []);
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
     * The date the partner agreement started. `$nif` is only for callers allowed to consult another
     * partner. UNVERIFIED: returns the raw answer text.
     */
    public function partnerAgreementDate(?Nif $nif = null): string
    {
        return $this->fetchText(Endpoint::PartnerAgreementDate, ['nif' => $nif?->value()]);
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
        $send = fn (): array => $this->caller->get($endpoint->path($this->version), $query, $name);

        return $this->guard === null ? $send() : $this->guard->call($endpoint, $name, $query, $send);
    }

    /** @param array<string, string|int|list<string>|null> $query */
    private function fetchText(Endpoint $endpoint, #[SensitiveParameter] array $query): string
    {
        return $this->caller->getText($endpoint->path($this->version), $query, $this->name($endpoint));
    }

    /** The endpoint as it appears in the path and in exceptions. */
    private function name(Endpoint $endpoint): string
    {
        return $endpoint->name($this->version);
    }

    /** authorizedNif is only for a third party's supplies: for the account itself it must be omitted. */
    private function authorized(?Nif $nif): ?string
    {
        if ($nif !== null && $this->holder !== null && $nif->value() !== $this->holder->value()) {
            throw new InvalidRequestException('This client reads the supplies of one holder; use forHolder() for another one.');
        }

        $nif ??= $this->holder;

        return $nif === null || $nif->value() === $this->config->username ? null : $nif->value();
    }

    private function assertThirdParty(Nif $nif): void
    {
        if ($nif->value() === $this->config->username) {
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

    /** @return array{Cups, string, int} */
    private function queryable(Supply $supply): array
    {
        if (! $supply->isQueryable()) {
            throw new InvalidRequestException('The supply was listed without a usable CUPS, distributor code or point type; list the supplies again.');
        }

        return [Cups::fromString($supply->cups), $supply->distributorCode, $supply->pointType];
    }

    private function assertDistributorCode(string $code): void
    {
        if (! Supply::isValidDistributorCode($code)) {
            throw new InvalidRequestException('The distributor code must be 1 to 10 letters, digits, dashes or underscores.');
        }
    }

    private function assertPointType(int $pointType): void
    {
        if (! Supply::isValidPointType($pointType)) {
            throw new InvalidRequestException("The point type must be between 1 and 5, {$pointType} given.");
        }
    }

    /** Datadis serves the last 24 months (the boundary month is refused) and no future month. */
    private function assertRange(Month $from, Month $to): void
    {
        if ($from->isAfter($to)) {
            throw new InvalidRequestException('The first month must not be after the last one.');
        }

        $now = $this->now();

        foreach ([$from, $to] as $month) {
            if (! $month->isWithinHistory($now)) {
                throw new InvalidRequestException('Datadis only serves the last '.Month::HISTORY_MONTHS." months up to the current one; {$month->format()} is outside that window.");
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
