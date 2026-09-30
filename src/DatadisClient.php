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
use Lenorix\DatadisClient\Data\Group;
use Lenorix\DatadisClient\Data\MaxPowerReading;
use Lenorix\DatadisClient\Data\ReactiveEnergy;
use Lenorix\DatadisClient\Data\Supply;
use Lenorix\DatadisClient\Exceptions\DatadisException;
use Lenorix\DatadisClient\Exceptions\InvalidRequestException;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\DatadisClient\Exceptions\UnsupportedOperationException;
use Lenorix\DatadisClient\Guard\RequestLedger;
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
    private const string API = '/api-private/api/';

    /** The endpoints subject to the 24 hour repetition rule. */
    private const array GUARDED = ['get-consumption-data', 'get-max-power', 'get-reactive-data'];

    private readonly ApiCaller $caller;

    private readonly ClockInterface $clock;

    private readonly DateTimeZone $timeZone;

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
        private readonly ?RequestLedger $ledger = null,
    ) {
        $this->clock = $clock ?? new SystemClock;
        $this->timeZone = $timeZone ?? new DateTimeZone('Europe/Madrid');

        $factory = new HttpFactory;
        $streamFactory ??= $factory;
        $requests = new RequestFactory($config, $requestFactory ?? $factory, $streamFactory);
        $transport = new Transport($http ?? GuzzleClientFactory::create($config), $streamFactory);
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

        // Rows keep their order, so the n-th row with the same date and time is its n-th occurrence.
        $seen = [];
        $decode = function (array $row) use (&$seen, $measurementType): ?ConsumptionReading {
            $key = json_encode([$row['date'] ?? null, $row['time'] ?? null]);
            $occurrence = $seen[$key] = ($seen[$key] ?? -1) + 1;

            return ConsumptionReading::fromRow($row, $this->timeZone, $measurementType, $occurrence);
        };

        return Envelope::build($decoded, 'timeCurve', $this->endpoint('get-consumption-data'), $decode);
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
     * The result usually holds zero or one ReactiveEnergy.
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

        return ReactiveEnergy::result($decoded, $this->endpoint('get-reactive-data'));
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
     * The supply groups defined in the account (v2 only).
     *
     * @return ApiResult<Group>
     */
    public function groups(): ApiResult
    {
        if ($this->version !== ApiVersion::V2) {
            throw new UnsupportedOperationException('Groups exist only in API v2.');
        }

        $decoded = $this->caller->get(self::API.'get-groups-v2', [], 'get-groups-v2');

        return Envelope::build($decoded, 'groups', 'get-groups-v2', static fn (array $row) => Group::fromRow($row));
    }

    /**
     * The users linked to the partner account (Datadis partner programme). UNVERIFIED: the official
     * documentation does not describe the answer, so the decoded JSON is returned as is.
     *
     * @return array<array-key, mixed>
     */
    public function partnerUsers(): array
    {
        return $this->caller->get(self::API.'partner-user-list', [], 'partner-user-list');
    }

    /**
     * Unlinks a user from the partner account. It changes data, so it is never retried
     * automatically. UNVERIFIED: returns the raw answer text.
     */
    public function partnerDeleteUser(Nif $nif): string
    {
        return $this->caller->getText(self::API.'partner-delete-user', ['nif' => $nif->value()], 'partner-delete-user');
    }

    /**
     * The date the partner agreement started. `$nif` is only for callers allowed to consult another
     * partner. UNVERIFIED: returns the raw answer text.
     */
    public function partnerAgreementDate(?Nif $nif = null): string
    {
        return $this->caller->getText(self::API.'partner-agreement-date', ['nif' => $nif?->value()], 'partner-agreement-date');
    }

    /**
     * @param  array<string, string|int|list<string>|null>  $query
     * @return array<array-key, mixed>
     */
    private function get(string $name, #[SensitiveParameter] array $query): array
    {
        $endpoint = $this->endpoint($name);

        if ($this->ledger === null || ! in_array($name, self::GUARDED, true)) {
            return $this->caller->get(self::API.$endpoint, $query, $endpoint);
        }

        $account = $this->config->username;
        $key = $this->repetitionKey($name, $query);
        $last = $this->ledger->lastAttempt($account, $key);

        if ($last !== null) {
            throw new RepetitionWindowException(
                "{$endpoint}: the same query was already sent at {$last->format(DATE_ATOM)}; Datadis refuses repeating it within 24 hours.",
                endpoint: $endpoint,
                requestSent: false,
            );
        }

        $this->ledger->record($account, $key);

        try {
            return $this->caller->get(self::API.$endpoint, $query, $endpoint);
        } catch (DatadisException $e) {
            if (! $e->requestSent) {
                try {
                    $this->ledger->forget($account, $key);
                } catch (\Throwable) {
                    // The original failure matters more; the entry expires with the window.
                }
            }

            throw $e;
        }
    }

    /**
     * The parameters Datadis keys its 24 hour rule on. The official manual lists authorizedNif for
     * consumption but not for maximum power, so for maximum power and reactive data (same
     * parameters) it is left out: two such queries that differ only in authorizedNif collide.
     *
     * @param  array<string, string|int|list<string>|null>  $query
     * @return array<string, string|int|list<string>|null>
     */
    private function repetitionKey(string $name, #[SensitiveParameter] array $query): array
    {
        return $name === 'get-consumption-data' ? $query : array_merge($query, ['authorizedNif' => null]);
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

    /**
     * The current moment on the Madrid calendar. Datadis is a Spanish service and is assumed to
     * judge its month window by the Madrid calendar even for Canary Islands data (UNVERIFIED).
     */
    private function now(): DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new DateTimeZone(Month::SERVICE_TIME_ZONE));
    }
}
