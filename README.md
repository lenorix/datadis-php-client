# Datadis client for PHP

[![Latest Version on Packagist](https://img.shields.io/packagist/v/lenorix/datadis-client.svg?style=flat-square)](https://packagist.org/packages/lenorix/datadis-client)
[![Tests](https://github.com/lenorix/datadis-php-client/actions/workflows/run-tests.yml/badge.svg)](https://github.com/lenorix/datadis-php-client/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/lenorix/datadis-client.svg?style=flat-square)](https://packagist.org/packages/lenorix/datadis-client)

Read electricity data from [Datadis](https://datadis.es), the platform where the Spanish distributors publish the data of every supply point: supplies, contracts, hourly and quarter-hourly consumption, maximum power, reactive energy, authorizations and the public open data.

The package takes care of the parts of Datadis that are easy to get wrong: the rule that refuses repeating a query for 24 hours, hour labels that end at `24:00`, daylight saving days with 23 or 25 hours, answers that come back empty instead of failing, and errors that look like one thing and mean another. It works with any framework, or none.

- [Installation](#installation)
- [How Datadis works, in one minute](#how-datadis-works-in-one-minute)
- [Quick start](#quick-start)
- [Common tasks](#common-tasks)
- [Working with the results](#working-with-the-results)
- [When something goes wrong](#when-something-goes-wrong)
- [Setting it up for production](#setting-it-up-for-production)
- [Using it in a Laravel application](#using-it-in-a-laravel-application)
- [Things that catch people out](#things-that-catch-people-out)
- [What is not verified yet](#what-is-not-verified-yet)

## Installation

```bash
composer require lenorix/datadis-client
```

You need PHP 8.4 or later with the `mbstring` and `zlib` extensions (both are in almost every PHP build). For the private API you also need a Datadis account: the NIF, NIE or CIF you registered with and its password.

## How Datadis works, in one minute

- **You log in with your own account**, whether the supplies are yours or belong to someone who authorized you. To read a third party's supplies you pass their NIF as `authorizedNif`. For your own supplies you must leave it out; the client does that for you.
- **A supply is identified by its CUPS**, but every data call also needs its **distributor code** and **point type**, and only the supplies list has them. So the first call is always the supplies list.
- **Data is asked for by whole months** (`2026/03`), within the last 24 months. The current month has data up to about two days ago; one report says its last hours may come as zeros until they are published, so do not read a run of zeros at the end of the current month as real.
- **Consumption, maximum power and reactive data can be asked once a day.** Datadis refuses the identical query for 24 hours, and a request it rejects counts too. Read [the 24 hour rule](#the-24-hour-rule) before writing a sync job.

## Quick start

```php
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Time\Month;
use Lenorix\DatadisClient\Values\Cups;

$client = new DatadisClient(new DatadisConfig('A00000000', 'your-password'));

// 1. Find the supply: it carries the codes every other call needs (consumption needs them all).
$supply = $client->findSupply(Cups::fromString('ES0000000000000000AA0A'));

if ($supply === null || ! $supply->isQueryable()) {
    throw new RuntimeException('This account cannot see that supply.');
}

// 2. Ask for a month of hourly consumption: the supply gives its CUPS and codes exactly as
//    Datadis listed them.
$result = $client->getConsumptionDataOf($supply, Month::of(2026, 7));

foreach ($result->records as $reading) {
    echo $reading->start?->format('Y-m-d H:i'), '  ', $reading->consumptionKWh, " kWh\n";
}
```

## Common tasks

### Read someone else's supplies

The holder must have authorized your account in Datadis first. Then ask for a client for that holder: it sends their NIF as `authorizedNif` on every call, so none can forget it and end up asking about your own account.

```php
use Lenorix\DatadisClient\Values\Nif;

$holder = $client->forHolder(Nif::fromString('00000000T'));

$supplies = $holder->getSupplies();
$supply = $holder->findSupply(Cups::fromString('ES0000000000000000AA0A'))
    ?? throw new RuntimeException('That holder has not authorized this supply.');
$result = $holder->getConsumptionDataOf($supply, Month::of(2026, 7));
```

It shares the login and the 24 hour guard with `$client`, which keeps reading your own supplies. Passing a different `authorizedNif` to a holder's client is refused before anything is sent. You can also pass `authorizedNif` call by call on the account client instead.

### Get the contract and its access tariff

```php
$contract = $client->getContractDetailOf($supply)->records[0] ?? null;

$contract?->tariff();              // AccessTariff::T20TD, T30TD, T61TD... or null when unsure
$contract?->contractedPowerkW;     // ['3.45', '3.45'], one per power period
$contract?->isOpenEnded();         // true while the contract is running
```

`tariff()` reads the free-text `accessFare` ("BAJA TENSION y POTENCIA <= 15 kW") and checks it against the number of contracted powers. It answers `null` rather than guess.

### Add up the energy of a month

Values are decimal strings, so add them with a decimal library (`brick/math` is already installed with this package), never with floats:

```php
use Brick\Math\BigDecimal;

$total = BigDecimal::zero();

foreach ($result->records as $reading) {
    if ($reading->hasValidTime()) {   // see "Things that catch people out"
        $total = $total->plus($reading->consumptionKWh);
    }
}
```

### Know which tariff period each hour belongs to

Both calendars of Circular CNMC 3/2020 ship with the package: the fixed one of 2.0TD (P1 to P3) and the six-period one of 3.0TD and 6.1TD to 6.4TD, with its seasons, its high and medium hours, and the differences of the Balearic and Canary Islands, Ceuta and Melilla. Weekends, 6 January and the national holidays count; regional and local holidays do not.

```php
use Lenorix\DatadisClient\Calendar\Territory;

$territory = Territory::fromPostalCode($supply->postalCode) ?? Territory::Peninsula;
$periods = $contract->tariff()?->schedule($territory);   // FixedSchedulePeriods or SixPeriodSchedule

foreach ($result->records as $reading) {
    if ($periods !== null && $reading->hourOfDay !== null) {
        $period = $periods->periodFor($reading->day, $reading->hourOfDay);   // 1 to 3 for 2.0TD, 1 to 6 for the others
    }
}
```

### Quarter-hourly consumption, maximum power, reactive energy

```php
use Lenorix\DatadisClient\Values\MeasurementType;

$startDate = Month::of(2026, 5);
$endDate = Month::of(2026, 7);

$quarters = $client->getConsumptionDataOf($supply, $startDate, $endDate, MeasurementType::QuarterHourly);
$peaks = $client->getMaxPowerOf($supply, $startDate, $endDate);         // one row per tariff period, in kW
$reactive = $client->getReactiveDataOf($supply, $startDate, $endDate);  // API v2 only
```

Leave `$endDate` out to ask for one month. Each `...Of()` call has a twin that takes the CUPS and codes one by one (`getConsumptionData()`, `getMaxPower()`, `getReactiveData()`, `getContractDetail()`), for when you stored them yourself; send the CUPS exactly as the supplies list gave it.

Quarter-hourly data is only available for some meters; for the others Datadis answers with an empty list.

### Fill in the last two years without wasting queries

`MonthPlanner` turns the range you want into the requests worth making: only months Datadis serves and, given the supply, only months of its contract.

```php
use Lenorix\DatadisClient\Exceptions\NoDataException;
use Lenorix\DatadisClient\Exceptions\RepetitionWindowException;
use Lenorix\DatadisClient\Time\MonthPlanner;

$now = new DateTimeImmutable();
$current = Month::current($now);

foreach (MonthPlanner::ranges($current->addMonths(-23), $current, $now, supply: $supply) as [$from, $to]) {
    try {
        $result = $client->getConsumptionDataOf($supply, $from, $to);

        if ($result->isEmptyBecauseOfErrors()) {
            // the distributor failed; the query was sent, so it counts: try again tomorrow
        } elseif ($result->isEmpty()) {
            // not published yet: try again another day
        } else {
            // store $result->records, including ->raw if you want to reinterpret them later
        }
    } catch (NoDataException) {
        // Datadis said it has nothing (404, 204 or an empty body): same as an empty result
    } catch (RepetitionWindowException) {
        // already asked in the last 24 hours: try again tomorrow
    }
}
```

One month per request is the default on purpose: distributors time out on long ranges, and a failed long request costs as much as a short one.

### Keep the current month up to date every day

A job that asks for the current month every day cannot send the same query each time: Datadis refuses it for 24 hours, so any run that reaches Datadis a little earlier than the day before would be refused, and the month would only be updated every other day. The `getLatest...Of()` calls alternate the range with the day instead: the current month alone one day, the previous and the current month the next, so no query is repeated within 48 hours and each run brings today's data (and, every other day, the last days of the previous month too).

```php
$readings = $client->getLatestConsumptionDataOf($supply);   // ApiResult, as getConsumptionDataOf()
$peaks = $client->getLatestMaxPowerOf($supply);
```

Every other day the answer holds two months. Store the records by their time, since it repeats days you already have, and split them by month before adding anything up, so the previous month's readings do not end up in this month's totals:

```php
$byMonth = [];

foreach ($readings->records as $reading) {
    $byMonth[Month::fromDate($reading->day)->format()][] = $reading;   // '2026/09' => [...]
}
```

**Schedule the job in Madrid time**, at a fixed hour between about 04:00 and 22:00 (in Laravel, `->dailyAt('06:00')->timezone('Europe/Madrid')`). The range follows the calendar day in Madrid, so a job fixed in UTC can run twice on the same Madrid day, or skip one, when the clocks change, and a run near midnight can land on the next day's range; either way that run is refused and the day's data waits for the next one. Kept in that band, two runs that ask the same range are always at least 29 hours apart (30, or 29 across a clock change), well outside the 24 hours. A second run on the same day asks the same range again: with a shared ledger it is refused before sending, and without one Datadis refuses it; either way the next day is not affected. A contract that started this month has only one range, so it is updated every other day. Reactive data shares its 24 hour key with maximum power, so ask it for closed months only. `MonthPlanner::latest()` gives the same plan if you prefer to make the calls yourself.

### Authorizations, groups and partner accounts

```php
$nif = Nif::fromString('00000000T');
$client->newAuthorization($nif);                                     // let someone read all your supplies
$client->newAuthorization($nif, new DateTimeImmutable('2026-10-01'), new DateTimeImmutable('2027-09-30'), Cups::fromString('ES0000000000000000AA0A'));   // or some, for a period
$client->cancelAuthorization($nif);
$client->listAuthorization();                                           // who can read what

$client->getGroups();                                                   // supply groups (API v2)

$client->partnerUserList();                                             // partner accounts only: PartnerUser results
$client->partnerDeleteUser($nif);
$client->partnerAgreementDate();                                        // the date as Datadis writes it, or null
```

### Public open data

Aggregated consumption by region, tariff, sector and more, with no supply involved:

```php
use Lenorix\DatadisClient\PublicApi\Community;
use Lenorix\DatadisClient\PublicApi\PublicSearchQuery;
use Lenorix\DatadisClient\PublicApiClient;

$api = new PublicApiClient($config);   // it needs your account too: without the token Datadis answers 401
$query = new PublicSearchQuery(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'), [Community::Madrid]);

$walk = $api->apiSearchAll($query);

foreach ($walk as $record) {
    echo $record->date()?->format('Y-m-d'), '  ', $record->sumEnergy(), " kWh\n";
}

$walk->getReturn()->skippedRows;   // rows of any page that could not be read and were left out
```

`apiSearchAll()` reads page after page until one comes back short, at most 1000 pages unless you pass another `maxPages` (at least 1). If it reaches the limit and the last page was full, more records may remain: after yielding every record it read, it throws a `PageLimitReachedException` whose `nextPage` is where to go on (a query starting at that page) and whose `skippedRows` counts the rows left out so far. When it ends on its own, `getReturn()` gives a `PageWalk` with the pages read and the rows left out. There are also `apiSumSearch()`, `apiSearchAuto()` and `apiSumSearchAuto()`.

## Working with the results

Every list method returns an `ApiResult`:

| | |
|---|---|
| `records` | The decoded rows. Each keeps the row in `raw` as received, with decimal numbers as their exact text. |
| `isEmpty()` | Nothing came back. Normal for a month not published yet. It is never "zero consumption". |
| `distributorErrors` | Failures of a distributor that Datadis reports inside a successful answer (API v2). |
| `isEmptyBecauseOfErrors()` | Empty because a distributor failed, not because there is no data. |
| `skippedRows` | Rows that could not be used, for example rows Datadis sends with no consumption value. |

Some conventions hold everywhere:

- **Names are Datadis's own.** Methods are named after the endpoints (`get-consumption-data` is `getConsumptionData()`, `api-search` is `apiSearch()`), and every field Datadis sends keeps its key exactly, odd spelling included (`consumptionKWh`, `contractedPowerkW`, `municipioCode`, `codeDescription`), so anything in the official documentation can be found here by its name. Only values the client works out itself have names of their own (`start`, `end`, `hourOfDay`, `day`).

- **Numbers are decimal strings, never floats, and never rounded**: every digit Datadis sends is kept, also of numbers sent as JSON numbers, which never go through a float (so `raw` holds a decimal JSON number as its text, `"0.301"`), written with at least three decimals for energy (kWh), maximum power (kW) and installed capacity, and at least two for contracted power (`3.45`, `1.725`). Installed capacity comes in whatever unit Datadis sends: the documentation says kW, its only sample looks like W, so check it against your own data.
- **Dates and times are `DateTimeImmutable`** in the zone the client was given (Europe/Madrid by default). A contract or supply without an end has `null` there and `isOpenEnded()` returns `true`.
- **Consumption rows keep the order Datadis sent them in**, and each one knows its real interval: `start`, `end`, `index` (hour 0 to 23, or quarter 0 to 95) and `hourOfDay` (0 to 23 for both).

### Hours and daylight saving time

Datadis labels hours `01:00` to `24:00`, and each label marks the **end** of the hour. On the last Sunday of October one hour happens twice and Datadis sends `03:00` twice; on the last Sunday of March `03:00` is missing. The client places every row on its real hour, so the two `03:00` rows get two different intervals. Do not key readings by date and time, and do not expect 24 rows per day.

For supplies in the Canary Islands, give the client their time zone:

```php
use Lenorix\DatadisClient\Calendar\Territory;

$client = new DatadisClient($config, timeZone: Territory::Canarias->timeZone());
```

## When something goes wrong

Every failure while talking to Datadis is a `DatadisException`. What to do depends on the kind:

| Exception | What it means | What to do |
|---|---|---|
| `NoDataException` | Datadis answered 404, 204 or an empty body for a data query. | Treat it like an empty result. A month not published yet usually comes back as an empty result instead. |
| `RepetitionWindowException` | The same query was made in the last 24 hours. | Wait. Retrying does not help. |
| `AuthorizationException` | You are not authorized for that CUPS, the holder's authorization expired, or the supply codes are stale. | Check the authorization in Datadis; reload the supply. |
| `AuthenticationException` | Wrong username or password, or Datadis rejected the token of a call that is not safe to repeat: a consumption, maximum power or reactive query, or a change (an authorization, unlinking a user). Those are never sent twice. | Fix the credentials. If `requestSent` is `true`, treat a data query as used for today, and check in Datadis whether a change was applied before making it again. |
| `RequestRejectedException` | Datadis refused the parameters. | Fix the request. Never resend it as it was: it would be refused again, and it may count against the 24 hour rule. |
| `InvalidRequestException` | The client refused the request before sending it (a future month, a reversed range...). | Fix the request. Nothing was sent. |
| `ServiceUnavailableException` | Datadis or a distributor failed. | Try again later. A consumption, maximum power or reactive query that reached Datadis counts against the 24 hour rule: check `requestSent` and treat it as used for today. |
| `TransportException` | The network failed or timed out. | The request may have reached Datadis: treat a data query as used for today. |
| `UninterpretableResponseException` | Datadis answered something unreadable (a maintenance page, an unknown shape). | Try again later; if it persists, report it. |
| `PageLimitReachedException` | `apiSearchAll()` or `apiSearchAutoAll()` read as many pages as allowed and the last one was full: more records may remain. Every record read was yielded first. | Go on from `nextPage`, or raise `maxPages`. |
| `ConfigurationException`, `UnsupportedOperationException`, `LedgerUnavailableException` | A setup problem. Nothing was sent. | Fix the setup. |

Each exception also tells you:

- `requestSent`: `false` only when the request certainly never left. That is the one case where a data query is still available today.
- `httpStatus` and `endpoint`.
- `detail`: what Datadis answered, with CUPS, NIF, tokens and passwords removed. Messages never contain them either, so they are safe to log.

```php
use Lenorix\DatadisClient\Exceptions\DatadisException;
use Lenorix\DatadisClient\Exceptions\NoDataException;

try {
    $result = $client->getConsumptionData($cups, $code, $pointType, $from, $to);
} catch (NoDataException) {
    // nothing yet
} catch (DatadisException $e) {
    $logger->warning($e->getMessage(), ['status' => $e->httpStatus, 'sent' => $e->requestSent]);
}
```

Value objects such as `Cups`, `Nif` and `Month` throw a plain `InvalidArgumentException` for malformed input. Use `Cups::isValid()` and `Nif::isValid()` first when the value comes from a user or a document. `Nif` also checks the control letter of a NIF, NIE or CIF, so a typo fails here instead of being sent and refused (a refused data query still counts against the 24 hour rule); pass `checkControl: false` to take one as it is.

## Setting it up for production

### The 24 hour rule

Datadis refuses an identical consumption, maximum power or reactive query for 24 hours, and counts every call it receives, even the ones it rejects. The client never repeats such a query by itself and refuses locally what it knows Datadis would reject, such as a range that starts before the contract of a supply passed to the `...Of()` calls. It also remembers the queries it sent, in memory: the same client refuses to repeat one. That protects one process only. To cover every worker and every run of your jobs, give the client a ledger backed by any PSR-16 cache they all share:

```php
use Lenorix\DatadisClient\Guard\RequestFingerprinter;
use Lenorix\DatadisClient\Guard\RequestLedger;

$ledger = new RequestLedger($psr16Cache, new RequestFingerprinter($aSecretOfAtLeast16Bytes));
$client = new DatadisClient($config, ledger: $ledger);
```

A repeat fails with a `RepetitionWindowException` whose `requestSent` is `false`, before anything is sent, and which says when the query was last attempted (`lastAttemptAt`) and from when it is allowed again (`availableAt`). Datadis's own 429 tells neither, so both are null then. Only a keyed hash of each query is stored, never the CUPS.

The ledger remembers each query for 24 hours and 10 minutes, a margin for the clocks of your servers and of Datadis. Pass `windowSeconds:` to change it, never below 24 hours. A job that runs every day should not shorten it to fit: use [the daily calls](#keep-the-current-month-up-to-date-every-day), which never repeat a query from one day to the next. If your application keeps a record of its own of what it asked, let the ledger decide alone: two records with different windows refuse different calls (see [moving from a record of your own](#moving-from-a-record-of-your-own)).

If Datadis rejects the token of such a query (a 401, rare, since the token is renewed before it expires), the client does not send it again, because Datadis may already have counted it: you get an `AuthenticationException` with `requestSent = true`, and the next call logs in again.

PSR-16 cannot store a key only if it is absent, so with a plain PSR-16 cache the ledger checks and records in two steps, and two workers that start the same query at the same instant can both send it. If your store can add atomically (Redis, Memcached, a database), wrap that call in an `AtomicStore` and pass it too: checking and recording become one step, and only one worker sends. With Laravel's cache it is one line, as in [the Laravel section](#using-it-in-a-laravel-application).

### Moving from a record of your own

An application that already keeps the queries it sent, and checks that record before each call, should let the ledger be the only one that decides whether a query may go. The ledger builds its key from exactly what the client sends, so no code outside it has to rebuild the parameters of Datadis, and checking and recording are one step with an `AtomicStore`. Its idea of "the same query" is also the one Datadis showed: maximum power and reactive data with the same parameters are the same query, `authorizedNif` does not count for either, and the endpoint itself is not part of the key. A record keyed on the endpoint, or on `authorizedNif` for maximum power, decides differently.

Check-then-call becomes call-and-catch. A local refusal sends nothing:

```php
try {
    $result = $client->getConsumptionDataOf($supply, $month);
} catch (RepetitionWindowException $e) {
    if ($e->httpStatus === null) {
        // refused by the ledger, nothing sent: skip it until $e->availableAt
    }
    // a 429 from Datadis itself: sent, and refused; it counts for today
}
```

A command that must not send anything when the query is still blocked does the same: the refusal comes before any request. Keep your record as a history of what was sent if you want one, written after the call; it no longer needs a key.

Three things to plan when you switch:

- **The queries of the last day.** The ledger starts empty. Tell it what you sent, with the same arguments you would give to the call and when it was sent; it builds each query exactly as the call does (the holder, the account's own NIF, one month as a range of one), so it refuses those very queries until their windows end:

  ```php
  foreach ($sentInTheLastDay as $sent) {   // rebuilt from your own data: the supply, the month, the endpoint
      $client->rememberConsumptionData($sent->at, $sent->cups, $sent->distributorCode, $sent->pointType, $sent->month);
  }
  // also rememberMaxPower() and rememberReactiveData()
  ```

  An attempt older than the window is skipped and the newest attempt of a query wins. Import before any worker sends with the ledger, with the workers paused. If you cannot tell what you sent (your record keeps only hashes), keep your old check, read only, beside the ledger for one window, 24 hours and 10 minutes, then remove it: together they refuse everything either of them would.
- **A store that survives deploys.** A ledger in a cache that a deploy clears (`cache:clear`, `optimize:clear` in Laravel) starts empty and can repeat a query sent minutes before. Use a store of its own that nothing clears, such as a separate Laravel cache store on Redis or on a database table, or a small `CacheInterface` and `AtomicStore` over a table of your own with a unique key.
- **Every worker on the same store**, with an `AtomicStore`, so two workers never send the same query at once.

A measurement that must send the same query twice on purpose, to see Datadis's own answer, needs a second client with a ledger of its own (or none, which means its own in memory).

### Share the login token

The token lasts 24 hours. By default each client keeps its own; give a PSR-16 cache to share it between processes, and protect that cache like a password:

```php
$client = new DatadisClient($config, tokenCache: $psr16Cache);
```

### Timeouts

Datadis can be slow, and a request that times out may still have counted. The default timeout is 120 seconds; do not go much lower.

```php
$config = new DatadisConfig('A00000000', 'your-password', timeout: 120.0, connectTimeout: 10.0);

// or from the settings your application already keeps
$config = DatadisConfig::fromArray(['username' => 'A00000000', 'password' => 'your-password', 'timeout' => '120']);
```

### Retry transient failures safely

`RetryingClient` retries network failures and gateway errors with backoff, but only for calls where repeating is harmless (login, supplies, distributors, contract detail, groups, the authorization list, the partner reads and the public API searches). Data queries and changes are never retried.

```php
use Lenorix\DatadisClient\Http\GuzzleClientFactory;
use Lenorix\DatadisClient\Http\RetryingClient;

$client = new DatadisClient($config, http: new RetryingClient(GuzzleClientFactory::create($config)));
```

### Use your own HTTP client

Guzzle is used by default, but any PSR-18 client works (and PSR-17 factories, if you prefer others):

```php
$client = new DatadisClient($config, http: $psr18Client, requestFactory: $psr17Factory, streamFactory: $psr17Factory);
```

Configure it not to follow redirects and not to decompress answers by itself: some Datadis answers claim to be compressed when they are not, and the client handles that. `GuzzleClientFactory::create()` shows the settings.

### API version

The client uses API v2 by default. Pass `version: ApiVersion::V1` for the older endpoints; reactive energy and groups exist only in v2. Authorizations and partner calls work with either.

## Using it in a Laravel application

The package does not depend on any framework, but it is ready to be configured from one: `DatadisClient::fromArray()` and `DatadisConfig::fromArray()` take the same array you keep in your configuration, with the values as the environment gives them (text such as `"120"` or `"v1"` is fine, empty values count as not given, and keys the package does not know are ignored, so your own Datadis settings can live next to them).

Datadis is a third-party service, so its credentials go in `config/services.php`, like any other:

```php
'datadis' => [
    'username' => env('DATADIS_USERNAME'),
    'password' => env('DATADIS_PASSWORD'),
    'api_version' => env('DATADIS_API_VERSION', 'v2'),
    'timezone' => env('DATADIS_TIMEZONE', 'Europe/Madrid'),
    'timeout' => env('DATADIS_TIMEOUT', 120),
],
```

Then bind the client in `app/Providers/AppServiceProvider.php`:

```php
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Lenorix\DatadisClient\DatadisClient;
use Lenorix\DatadisClient\DatadisConfig;
use Lenorix\DatadisClient\Guard\AtomicStore;
use Lenorix\DatadisClient\Guard\RequestFingerprinter;
use Lenorix\DatadisClient\Guard\RequestLedger;
use Lenorix\DatadisClient\Http\GuzzleClientFactory;

public function register(): void
{
    // bind, not singleton: the client is built where it is used, after any Http::fake() in a test.
    $this->app->bind(DatadisClient::class, function () {
        $settings = config('services.datadis');

        return DatadisClient::fromArray(
            $settings,
            // The package's Guzzle settings (timeouts, no redirects, no automatic decompression)
            // on Laravel's handler stack, so Http::fake() and Http::assertSent() see every call.
            http: GuzzleClientFactory::create(DatadisConfig::fromArray($settings), ['handler' => Http::buildHandlerStack()]),
            // The token and the 24 hour guard live in the cache every worker shares. Cache::add() is
            // atomic on Redis, Memcached and the database store, so two workers never both send a query.
            tokenCache: Cache::store(),
            ledger: new RequestLedger(
                Cache::store(),
                new RequestFingerprinter(config('app.key')),
                atomic: new class implements AtomicStore
                {
                    public function add(string $key, mixed $value, int $ttlSeconds): bool
                    {
                        return Cache::add($key, $value, $ttlSeconds);
                    }
                },
            ),
        );
    });
}
```

Do not use `Http::buildClient()` for this: it ignores the pending timeout and decompression options.

Inject `DatadisClient` wherever you need it (controllers, jobs, commands). The client holds a password and cannot be serialised, so a queued job resolves it in `handle()` instead of keeping it in a property.

In your tests, fake Datadis like any other HTTP service:

```php
Http::preventStrayRequests();
Http::fake([
    'datadis.es/nikola-auth/tokens/login' => Http::response('a.fake.jwt', 200, ['Content-Type' => 'text/plain']),
    'datadis.es/api-private/api/get-supplies-v2*' => Http::response(['supplies' => [], 'distributorError' => []]),
]);

app(DatadisClient::class)->getSupplies();

Http::assertSent(fn ($request) => $request->hasHeader('Accept', 'application/json'));
```

The same works in any framework: read the settings however it does, pass them to `fromArray()`, and give the client the HTTP client and PSR-16 cache the framework already has.

## Things that catch people out

- **Send the CUPS exactly as the supplies list returns it.** Datadis refuses the same CUPS in lowercase or without its last two characters as "not authorized". `findSupply()` accepts either form and gives you back the right one.
- **An empty answer is not always "no data yet".** A wrong distributor code that happens to exist also gives an empty answer. Always take the codes from the supplies list.
- **Quarter-hourly labels come in one of two conventions, and which one is not verified.** It may be the end of each quarter (`00:15` to `24:00`, like the hourly labels) or the hour that ends followed by the minute the quarter starts (`01:00` is 00:00-00:15, `24:45` is 23:45-24:00). The client recognises which one each answer uses from the labels only one of them has, and places every quarter on its real time, daylight saving days included. If an answer cannot tell (a partial day without hour `00` or `24:15` to `24:45`), its rows come without `start` and `end` rather than with a guess.
- **Rows can lack a valid time.** Some distributors send an extra `00:00` row on normal days. It is kept, with `hasValidTime()` returning `false` and no `start`, `end` or `index`. Decide what to do with it before adding up energy.
- **Store what you receive.** You cannot ask again for 24 hours, so keep `raw` if you might want to reinterpret the data later.
- **Nothing for the current day, little for the last two.** Distributors publish with a delay; a month can keep changing for some days after it ends.
- **Maximum power times mark the end of a quarter hour**, like consumption labels. A peak at `00:00` belongs to the last quarter of the previous day.
- **Keep credentials out of logs.** The package never logs anything and hides the password and token from `var_dump`, `print_r` and `var_export` of its objects, including the token cache and ledger store you give it, and the account NIF too. A `Nif` (a delegated holder, an `authorizedNif`) and a `Cups` hide their value from all three, and the arguments the package records in stack traces carry no NIF, CUPS or answer body. The records you get back (supplies, contracts, readings, authorizations, partner users, groups) show their CUPS, addresses, postal codes, names, documents and the answer as received as `[hidden]` in `var_dump`, `print_r` and `dump()`; read them from the properties as usual (`var_export` shows them, since they are public). Your cache shows the token if you dump it directly.

## What is not verified yet

Most behaviour here was checked against real Datadis answers, on both API versions. These parts have not been seen yet, and the client reads them tolerantly:

- Quarter-hourly labels of a point type 1, 2 or 3 supply. Two conventions are possible, and the client recognises either in each answer (see [Things that catch people out](#things-that-catch-people-out)).
- Reactive energy with data (only the answer for a period without data has been seen).
- A group, and a partner agreement date that is set.
- The answers of the calls that change data: `newAuthorization()`, `cancelAuthorization()` and `partnerDeleteUser()`.
- Hour labels of Canary Islands supplies around a daylight saving change.

[`docs/`](https://github.com/lenorix/datadis-php-client/tree/main/docs) in the repository records everything known about the API, with the evidence behind each point.

## Contributing

```bash
composer test            # Pest, including property-based tests with Eris
composer test-coverage   # fails under 100 % line coverage
composer phpstan         # static analysis at level max
composer lint            # Pint, checking only
composer format          # Pint, fixing
```

Property-based tests run 100 cases each; set `DATADIS_PBT_ITERATIONS` for longer runs. A failing property prints a seed: reproduce it with `ERIS_SEED=<seed> vendor/bin/pest --filter '<test name>'`. No test ever calls the real Datadis.

## Versioning and stability

This package follows [Semantic Versioning](https://semver.org). It is at 0.x: the API can still change, and a minor release (0.2.0) may break it, while patch releases (0.1.x) only fix bugs. `composer require lenorix/datadis-client` adds `^0.1`, which never moves to 0.2 on its own. Read the changelog before moving to a new minor version. 1.0.0 will follow once the parts listed under "What is not verified yet" are checked against real answers.

## Changelog

See the [CHANGELOG](https://github.com/lenorix/datadis-php-client/blob/main/CHANGELOG.md).

## Security Vulnerabilities

Please review [our security policy](https://github.com/lenorix/datadis-php-client/security/policy) on how to report security vulnerabilities.

## Credits

- [Jesus Hernandez](https://github.com/jhg)
- [All Contributors](https://github.com/lenorix/datadis-php-client/graphs/contributors)

## License

The Unlicense. See the [license file](LICENSE.md).
