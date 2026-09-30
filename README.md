# Datadis client for PHP

[![Latest Version on Packagist](https://img.shields.io/packagist/v/lenorix/datadis-client.svg?style=flat-square)](https://packagist.org/packages/lenorix/datadis-client)
[![Tests](https://github.com/lenorix/datadis-client/actions/workflows/run-tests.yml/badge.svg)](https://github.com/lenorix/datadis-client/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/lenorix/datadis-client.svg?style=flat-square)](https://packagist.org/packages/lenorix/datadis-client)

A client for [Datadis](https://datadis.es), the platform of the Spanish electricity distributors, plus the helpers you need to use its data: supplies, contracts, hourly and quarter-hourly consumption, maximum power, reactive energy, authorizations and the public open data API.

It is framework agnostic. HTTP goes through PSR-18 with Guzzle as the default, tokens can be shared through any PSR-16 cache, and every failure is a typed exception that tells you whether the request may have reached Datadis.

## Requirements

- PHP 8.4 or later.
- A Datadis account (the NIF/NIE/CIF you registered with and its password) for the private API. The public API needs no account.

## Installation

```bash
composer require lenorix/datadis-client
```

## Quick start

```php
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;

$client = new DatadisClient(new DatadisConfig('12345678Z', 'your-password'));

// Every other endpoint needs the distributor code and point type of the supply,
// and only the supplies list has them.
$supply = $client->findSupply(Cups::fromString('ES0031300000000001JN0F'));

if ($supply === null || ! $supply->isQueryable()) {
    throw new RuntimeException('No such supply in this account.');
}

$result = $client->consumption(
    Cups::fromString($supply->cups),
    $supply->distributorCode,
    $supply->pointType,
    Month::of(2026, 1),
    Month::of(2026, 1),
);

foreach ($result->records as $reading) {
    // $reading->kWh is a decimal string ("0.323"), $reading->start/end are the real interval.
    echo $reading->date, ' ', $reading->time, ' ', $reading->kWh, PHP_EOL;
}
```

## Read this before querying: the 24 hour rule

Datadis refuses an identical consumption, maximum power or reactive query for 24 hours with HTTP 429, and it counts calls **made**, not calls that succeeded: a rejected request burns the query too. Because of that:

- Invalid requests are refused locally before anything is sent (`InvalidRequestException`): bad distributor codes or point types, reversed ranges, future months, and months outside the last 24 (the month exactly two years back is refused by Datadis).
- Nothing that may have reached Datadis is retried automatically. A network failure while a data request is in flight counts as possibly sent, because PSR-18 cannot tell a request that never left from one that timed out while being read.
- `MonthPlanner::ranges()` turns a wanted range into requests that only cover months Datadis serves and, given a supply, months of its contract.
- An optional guard remembers attempts and refuses a repeat locally (see below).

Supplies, distributors and contract detail are not subject to the rule.

## Results

Every list method returns an `ApiResult`:

| Property / method | Meaning |
|-------------------|---------|
| `records` | The decoded DTOs. Each keeps the untouched row in `raw`. |
| `distributorErrors` | Partial failures Datadis reports inside an HTTP 200 (API v2). |
| `isEmpty()` | Nothing was returned. This is a normal answer (month not published yet), never zero consumption. |
| `isEmptyBecauseOfErrors()` | Empty because a distributor failed, not because there is no data. |
| `skippedRows` | Rows that could not be used (Datadis sends `null` consumption at times). |

Numbers are decimal strings with a fixed scale, never floats: three decimals for energy, maximum power and installed capacity, two for contracted power. Dates that Datadis sends empty for open-ended contracts are `null`, with `isOpenEnded()` to ask.

### Hours and daylight saving time

Consumption labels run `01:00` to `24:00` and mark the **end** of the interval. On the autumn change day `03:00` appears twice and on the spring change day it is missing. Readings keep the order they arrived in, and `start`, `end` and `index` place each one on the real hour: the two `03:00` rows get two different hours. Never key readings by `(date, time)`.

A row whose label cannot be placed (one distributor sends an extra `00:00` row on days that already have 24 hours) is kept with `hasValidTime() === false` and null `start`, `end` and `index`. Decide explicitly what to do with such rows before summing energy.

Dates are local civil time without an offset. Pass the zone of the supply when it is in the Canary Islands:

```php
use Lenorix\DatadisClient\Calendar\Territory;

$client = new DatadisClient($config, timeZone: Territory::Canarias->timeZone());
// Or derive it: Territory::fromPostalCode($supply->postalCode)?->timeZone()
```

## Errors

Every exception raised while talking to Datadis extends `DatadisException`, which carries `httpStatus`, `endpoint`, a redacted `detail` of what Datadis answered, and `requestSent`. `requestSent` is `false` only when the request provably never left: use it to decide whether a query is still available. CUPS, NIF, NIE, CIF, tokens and passwords are removed from messages and details.

| Exception | When |
|-----------|------|
| `ConfigurationException` | Invalid settings, before anything is sent. |
| `InvalidRequestException` | A request known to be wrong, before anything is sent. |
| `AuthenticationException` | Login refused, or a token refused even after one new login. |
| `AuthorizationException` | 403: no valid authorization for that CUPS, or stale supply codes. |
| `NoDataException` | 404, 204 or an empty body. |
| `RepetitionWindowException` | 429 from Datadis, or refused locally by the guard (`requestSent = false`). |
| `RequestRejectedException` | 400 and other 4xx. Never resend the identical call. |
| `ServiceUnavailableException` | 5xx. |
| `TransportException` | The HTTP client failed. The outcome is unknown. |
| `UninterpretableResponseException` | An answer that cannot be read (HTML page, unknown shape). |
| `UnsupportedOperationException` | The operation does not exist in the chosen API version. |
| `LedgerUnavailableException` | The guard's store failed, so the query was not sent. |

Value objects (`Cups`, `Nif`, `Month`, labels) and helpers throw `InvalidArgumentException` for malformed input. A supply's `cups` comes from Datadis, so check it with `Cups::isValid()` before `Cups::fromString()` if you cannot trust it.

## Configuration

```php
$config = new DatadisConfig(
    username: '12345678Z',
    password: 'your-password',
    baseUrl: 'https://datadis.es', // HTTPS only
    timeout: 120.0,                // Datadis is slow: contract detail takes about 15 s
    connectTimeout: 10.0,
);
```

The password cannot be seen through `var_dump`, `print_r`, `var_export` or `serialize`.

### Another HTTP client (PSR-18)

The client only depends on PSR interfaces. Pass any PSR-18 client, and PSR-17 factories if you do not want Guzzle's:

```php
$client = new DatadisClient($config, http: $yourPsr18Client, requestFactory: $psr17, streamFactory: $psr17);
```

Whatever client you use: do not let it follow redirects or decode gzip on its own (some Datadis answers are labelled gzip without being gzip; the package sends `Accept-Encoding: identity` and inflates by itself). `GuzzleClientFactory::create($config)` shows the settings.

### Sharing the token

The token is kept until two minutes before it expires. Give a PSR-16 cache to share it between processes; it holds a live credential, so protect it like one:

```php
$client = new DatadisClient($config, tokenCache: $psr16Cache);
```

### Guarding the 24 hour rule

```php
use Lenorix\DatadisClient\Guard\RequestFingerprinter;
use Lenorix\DatadisClient\Guard\RequestLedger;

$ledger = new RequestLedger($psr16Cache, new RequestFingerprinter($aSecretOfAtLeast16Bytes));
$client = new DatadisClient($config, ledger: $ledger);
```

Attempts are recorded before sending and forgotten only when nothing was sent. Only an HMAC of the query is stored. Every process using the same account must share the store, and two workers racing on the same query can still both send it: serialise such work if it matters.

### Retrying transient failures

`RetryingClient` wraps any PSR-18 client and retries network failures and 502/503/504 with backoff, only where a repeat is harmless (login, supplies, distributors, contract detail, the authorization list and the public API):

```php
use Lenorix\DatadisClient\Http\GuzzleClientFactory;
use Lenorix\DatadisClient\Http\RetryingClient;

$client = new DatadisClient($config, http: new RetryingClient(GuzzleClientFactory::create($config)));
```

## API versions

v2 is the default. Use `ApiVersion::V1` for the older endpoints (bare lists, no distributor errors); reactive energy exists only in v2.

```php
$client = new DatadisClient($config, version: ApiVersion::V1);
```

Authorizations exist only in the v1 style and are available whatever the version: `newAuthorization()`, `cancelAuthorization()` and `authorizations()`. v2 also has `groups()`, and partner accounts have `partnerUsers()`, `partnerDeleteUser()` and `partnerAgreementDate()` (their answers are returned raw).

## Helpers

- `AccessFareParser::parse($contract->accessFare)` and `$contract->tariff()`: the access tariff (2.0TD, 3.0TD, 6.1TD to 6.4TD) read from the free-text description, `null` when unsure.
- `AccessTariff`: periods of each tariff and a check for contracted powers.
- `FixedSchedulePeriods`: the 2.0TD period of an hour, per territory. Implement `PeriodMapper` for the 3.0TD and 6.XTD calendars.
- `NationalHolidays`: the fixed-date national holidays that count for tariff periods (Good Friday does not).
- `Territory`: from a postal code, with its time zone.
- `MonthPlanner`, `Month`, `HourLabel`, `QuarterHourLabel`, `TimeInstant`, `Cups`, `Nif`, `PersonalDataRedactor`.

## Public API

```php
use Lenorix\DatadisClient\PublicApi\Community;
use Lenorix\DatadisClient\PublicApi\PublicApi;
use Lenorix\DatadisClient\PublicApi\PublicSearchQuery;

// The official manual asks for the login token on these calls too: pass your DatadisConfig.
// new PublicApi() calls without credentials.
$api = new PublicApi($config);
$query = new PublicSearchQuery(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'), [Community::Madrid]);

foreach ($api->searchAll($query) as $record) {
    echo $record->date()?->format('Y-m-d'), ' ', $record->energy(), ' kWh', PHP_EOL;
}
```

## What is not verified

Some behaviour could not be checked against real answers and is read tolerantly:

- The answers of the authorization and partner endpoints (not described anywhere) and whether the public API works without the token.
- Reactive energy: the shape follows the official documentation, no real answer has been seen.
- The unit of maximum power: the documentation says W, real household data says kW.
- The quarter-hourly label format.
- The token lifetime (the JWT `exp` claim decides) and whether Datadis judges its month window in Madrid time (assumed).

`docs/` holds everything known about the API and its quirks, with the evidence behind each point.

## Testing

```bash
composer test            # Pest, including property-based tests with Eris
composer test-coverage   # fails under 98 % line coverage
composer phpstan         # static analysis, level max
composer format          # Pint
```

Property-based tests run 100 cases each by default. Set `DATADIS_PBT_ITERATIONS` for longer campaigns. A failing property prints a seed: reproduce it with `ERIS_SEED=<seed> vendor/bin/pest --filter '<test name>'`.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Jesus Hernandez](https://github.com/jhg)
- [All Contributors](../../contributors)

## License

The Unlicense. Please see [License File](LICENSE.md) for more information.
