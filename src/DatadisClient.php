<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use GuzzleHttp\Psr7\HttpFactory;
use Lenorix\DatadisClient\Auth\SystemClock;
use Lenorix\DatadisClient\Auth\TokenProvider;
use Lenorix\DatadisClient\Data\ApiResult;
use Lenorix\DatadisClient\Data\Authorization;
use Lenorix\DatadisClient\Data\ConsumptionReading;
use Lenorix\DatadisClient\Data\ContractDetail;
use Lenorix\DatadisClient\Data\DistributorCodes;
use Lenorix\DatadisClient\Data\Envelope;
use Lenorix\DatadisClient\Data\MaxPowerReading;
use Lenorix\DatadisClient\Data\ReactiveEnergy;
use Lenorix\DatadisClient\Data\Supply;
use Lenorix\DatadisClient\Exceptions\InvalidRequestException;
use Lenorix\DatadisClient\Exceptions\UninterpretableResponseException;
use Lenorix\DatadisClient\Exceptions\UnsupportedOperationException;
use Lenorix\DatadisClient\Http\ApiCaller;
use Lenorix\DatadisClient\Http\GuzzleClientFactory;
use Lenorix\DatadisClient\Http\RequestFactory;
use Lenorix\DatadisClient\Http\Transport;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;
use Lenorix\DatadisClient\Values\MeasurementType;
use Lenorix\DatadisClient\Values\Nif;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Client of the Datadis private API.
 *
 * Read docs/quirks-and-rules.md before building anything that repeats calls: Datadis refuses an
 * identical consumption, max power or reactive query for 24 hours, and a rejected request counts.
 * Nothing here retries a request that may have been sent.
 *
 * Every list method returns an ApiResult. An empty result is a normal answer, never zero
 * consumption, and `distributorErrors` carries partial failures reported inside a 200.
 */
final class DatadisClient
{
    private const string API = '/api-private/api/';

    private readonly ApiCaller $caller;

    private readonly ClockInterface $clock;

    private readonly DateTimeZone $timeZone;

    /**
     * @param  ClientInterface|null  $http  any PSR-18 client; Guzzle is used when omitted
     * @param  CacheInterface|null  $tokenCache  any PSR-16 store to share the token between processes; it holds a live credential
     * @param  DateTimeZone|null  $timeZone  zone of the civil dates and times Datadis sends: Europe/Madrid by default, Atlantic/Canary for the Canary Islands
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
    ) {
        $this->clock = $clock ?? new SystemClock;
        $this->timeZone = $timeZone ?? new DateTimeZone('Europe/Madrid');

        $factory = new HttpFactory;
        $requests = new RequestFactory($config, $requestFactory ?? $factory, $streamFactory ?? $factory);
        $transport = new Transport($http ?? GuzzleClientFactory::create($config));
        $tokens = new TokenProvider($config, $requests, $transport, $tokenCache, $this->clock);

        $this->caller = new ApiCaller($requests, $transport, $tokens);
    }

    /**
     * The supply points of the account, or of the authorized third party.
     * Not subject to the 24 hour repetition rule.
     *
     * @return ApiResult<Supply>
     */
    public function supplies(?Nif $authorizedNif = null, ?string $distributorCode = null): ApiResult
    {
        if ($distributorCode !== null) {
            $this->assertDistributorCode($distributorCode);
        }

        $decoded = $this->get('get-supplies', ['authorizedNif' => $this->authorized($authorizedNif), 'distributorCode' => $distributorCode]);

        return Envelope::build($decoded, 'supplies', $this->endpoint('get-supplies'), fn (array $row) => Supply::fromRow($row, $this->timeZone));
    }

    /**
     * The supply of a CUPS, resolved from the supplies list (see SupplyMatcher). Null when the
     * account has no such supply. Check isQueryable() before using its codes.
     */
    public function findSupply(Cups $cups, ?Nif $authorizedNif = null): ?Supply
    {
        return SupplyMatcher::pick($this->supplies($authorizedNif)->records, $cups);
    }

    /**
     * The codes of the distributors that have supplies for the account. Codes are opaque strings.
     *
     * @return ApiResult<string>
     */
    public function distributors(?Nif $authorizedNif = null): ApiResult
    {
        $decoded = $this->get('get-distributors-with-supplies', ['authorizedNif' => $this->authorized($authorizedNif)]);

        return DistributorCodes::result($decoded, $this->endpoint('get-distributors-with-supplies'));
    }

    /**
     * Contract detail of one supply. Not subject to the 24 hour repetition rule.
     *
     * @return ApiResult<ContractDetail>
     */
    public function contractDetail(Cups $cups, string $distributorCode, ?Nif $authorizedNif = null): ApiResult
    {
        $this->assertDistributorCode($distributorCode);

        $decoded = $this->get('get-contract-detail', [
            'cups' => $cups->value(),
            'distributorCode' => $distributorCode,
            'authorizedNif' => $this->authorized($authorizedNif),
        ]);

        return Envelope::build($decoded, 'contract', $this->endpoint('get-contract-detail'), fn (array $row) => ContractDetail::fromRow($row, $this->timeZone));
    }

    /**
     * Consumption between two whole months, both included. Datadis refuses the identical query for 24 hours.
     *
     * `$pointType` and `$distributorCode` come from the supply. Quarter-hourly data is only offered for
     * some point types; Datadis decides, so it is not checked here.
     *
     * @return ApiResult<ConsumptionReading>
     */
    public function consumption(
        Cups $cups,
        string $distributorCode,
        int $pointType,
        Month $from,
        Month $to,
        MeasurementType $measurementType = MeasurementType::Hourly,
        ?Nif $authorizedNif = null,
    ): ApiResult {
        $this->assertDistributorCode($distributorCode);
        $this->assertPointType($pointType);
        $this->assertRange($from, $to);

        $decoded = $this->get('get-consumption-data', [
            'cups' => $cups->value(),
            'distributorCode' => $distributorCode,
            'startDate' => $from->format(),
            'endDate' => $to->format(),
            'measurementType' => $measurementType->value,
            'pointType' => $pointType,
            'authorizedNif' => $this->authorized($authorizedNif),
        ]);

        return Envelope::build($decoded, 'timeCurve', $this->endpoint('get-consumption-data'), fn (array $row) => ConsumptionReading::fromRow($row, $this->timeZone, $measurementType));
    }

    /**
     * Maximum power between two whole months, both included. Datadis refuses the identical query for 24 hours.
     *
     * @return ApiResult<MaxPowerReading>
     */
    public function maxPower(Cups $cups, string $distributorCode, Month $from, Month $to, ?Nif $authorizedNif = null): ApiResult
    {
        $this->assertDistributorCode($distributorCode);
        $this->assertRange($from, $to);

        $decoded = $this->get('get-max-power', [
            'cups' => $cups->value(),
            'distributorCode' => $distributorCode,
            'startDate' => $from->format(),
            'endDate' => $to->format(),
            'authorizedNif' => $this->authorized($authorizedNif),
        ]);

        return Envelope::build($decoded, 'maxPower', $this->endpoint('get-max-power'), fn (array $row) => MaxPowerReading::fromRow($row, $this->timeZone));
    }

    /**
     * Reactive energy between two whole months (v2 only). Datadis refuses the identical query for 24 hours.
     * The result holds zero or one ReactiveEnergy.
     *
     * @return ApiResult<ReactiveEnergy>
     */
    public function reactive(Cups $cups, string $distributorCode, Month $from, Month $to, ?Nif $authorizedNif = null): ApiResult
    {
        if ($this->version !== ApiVersion::V2) {
            throw new UnsupportedOperationException('Reactive data exists only in API v2.');
        }

        $this->assertDistributorCode($distributorCode);
        $this->assertRange($from, $to);

        $decoded = $this->get('get-reactive-data', [
            'cups' => $cups->value(),
            'distributorCode' => $distributorCode,
            'startDate' => $from->format(),
            'endDate' => $to->format(),
            'authorizedNif' => $this->authorized($authorizedNif),
        ]);

        $endpoint = $this->endpoint('get-reactive-data');
        $object = $decoded['reactiveEnergy'] ?? [];

        if (! is_array($object)) {
            throw new UninterpretableResponseException("{$endpoint}: \"reactiveEnergy\" is not an object.", endpoint: $endpoint);
        }

        $reactive = ReactiveEnergy::fromRow($object);

        return new ApiResult($reactive === null ? [] : [$reactive], Envelope::distributorErrors($decoded), 0, $decoded);
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

        return $this->caller->getText(self::API.'new-authorization', [
            'authorizedNif' => $authorizedNif->value(),
            'startDate' => $from?->format('Y/m/d'),
            'endDate' => $to?->format('Y/m/d'),
            'cups' => $this->cupsList($cups),
        ], 'new-authorization');
    }

    /**
     * Cancels a third party's authorization (for every supply when no CUPS is given).
     * v1 only and UNVERIFIED, like newAuthorization(). Returns the raw answer text.
     */
    public function cancelAuthorization(Nif $authorizedNif, Cups ...$cups): string
    {
        $this->assertThirdParty($authorizedNif);

        return $this->caller->getText(self::API.'cancel-authorization', [
            'authorizedNif' => $authorizedNif->value(),
            'cups' => $this->cupsList($cups),
        ], 'cancel-authorization');
    }

    /**
     * The authorizations of the account, or of the given owner. v1 only and UNVERIFIED.
     *
     * @return ApiResult<Authorization>
     */
    public function authorizations(?Nif $ownerNif = null): ApiResult
    {
        $decoded = $this->caller->get(self::API.'list-authorization', ['ownerNif' => $ownerNif?->value()], 'list-authorization');

        return Envelope::build($decoded, 'authorizations', 'list-authorization', fn (array $row) => Authorization::fromRow($row, $this->timeZone));
    }

    /**
     * @param  array<string, string|int|list<string>|null>  $query
     * @return array<array-key, mixed>
     */
    private function get(string $name, array $query): array
    {
        return $this->caller->get(self::API.$this->endpoint($name), $query, $this->endpoint($name));
    }

    private function endpoint(string $name): string
    {
        return $name.$this->version->suffix();
    }

    /** authorizedNif is only for a third party's supplies: for the account itself it must be omitted. */
    private function authorized(?Nif $nif): ?string
    {
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

    private function assertDistributorCode(string $code): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,10}$/D', $code) !== 1) {
            throw new InvalidRequestException('The distributor code must be 1 to 10 letters, digits, dashes or underscores.');
        }
    }

    private function assertPointType(int $pointType): void
    {
        if ($pointType < 1 || $pointType > 5) {
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

    private function now(): DateTimeImmutable
    {
        return $this->clock->now()->setTimezone($this->timeZone);
    }
}
