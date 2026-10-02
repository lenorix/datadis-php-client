# Changelog

All notable changes to `lenorix/datadis-client` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). While the version is 0.x, a minor release may break the public API.

## [Unreleased]

## [0.4.0] - 2026-10-02

### Added

- `getLatestConsumptionDataOf()` and `getLatestMaxPowerOf()`, and `MonthPlanner::latest()`, for a sync that runs every day: the range alternates between the current month and the previous plus the current month with the civil day, so no query is repeated from one day to the next. Asking the same range every day is refused by Datadis whenever a run starts earlier than the day before.
- `RequestLedger` takes `windowSeconds:` (24 hours and 10 minutes by default, never below 24 hours).

### Fixed

- The static analysis in CI refused `FILTER_VALIDATE_BOOL` in `filter_var()`; the configuration now uses `FILTER_VALIDATE_BOOLEAN`, the same value.

## [0.3.0] - 2026-10-02

Checked against the reference integration in production and the other Datadis clients: two cases could still cost a query for 24 hours.

### Changed

- Without a ledger, the client remembers the consumption, maximum power and reactive queries it sent, in memory, and refuses to repeat one within 24 hours (`RepetitionWindowException`). Before, it sent whatever it was asked. Give it a ledger on a shared store to cover several processes.

### Fixed

- A consumption, maximum power or reactive query whose token Datadis rejects (401) is not sent again: Datadis may have counted it. The token is dropped, the next call logs in again, and the query fails with an `AuthenticationException` whose `requestSent` is `true`; other calls are still repeated once.
- `getConsumptionDataOf()`, `getMaxPowerOf()` and `getReactiveDataOf()` refuse a range that starts before the month the supply's contract starts, which Datadis refuses with a 400 that counts for 24 hours.

## [0.2.0] - 2026-10-01

Checked against a new set of real answers (October 2026): the v2 paths, the authorization list, the partner calls and the public API.

### Changed

- `DatadisConfig::$username` is now the method `username()`. The account's NIF is kept like the password, so no dump of the configuration or the client shows it, `var_export` included.
- `DatadisConfig` refuses a username that is not a NIF, NIE or CIF, or whose control character does not match, before anything is sent. Pass `checkUsernameControl: false` (or `check_username_control` in `fromArray()`) to take one with the right shape and a control character that does not match.
- `Nif` requires the kind of control character the first letter of a CIF calls for: a digit for A, B, E and H, a letter for P, Q and S. `A0000000J` was accepted before.
- `Nif` takes a NIF K, L or M (Spaniards under 14 or living abroad, foreigners without an NIE) with the DNI letter of its seven digits, as the AEAT sets for these NIF (RD 1065/2007). Before, it checked them as a CIF and refused them with the right letter, even with `checkControl: false`. The redaction of error texts covers them too.
- `HourLabel::interval()` and `QuarterHourLabel::interval()` refuse a negative occurrence instead of answering null, as for an hour the clock never showed.
- `MonthPlanner::ranges()` takes any number of months per request above 24 as one request for the whole range.
- A `distributorError` that is not a list of objects (a number, text, `true`, or such items in the list) counts as a distributor failure. Before, it was dropped and an empty answer looked like "no data".
- `apiSearchAll()` and `apiSearchAutoAll()` refuse a `maxPages` below 1 with an `InvalidRequestException`, at the call. Before, they read no page and gave no records, which looked like no data.

- `PublicApiClient` needs a `DatadisConfig`: the public API answers 401 without the login token.
- `partnerUserList()` returns `PartnerUser` results and `partnerAgreementDate()` returns the date, or null, instead of the raw answer.
- The code description of reactive data is `codeDescription`, the key Datadis really sends.
- A quarter-hourly answer whose labels cannot tell which convention it uses (a partial day with neither hour `00` nor `24:15` to `24:45`) now gets readings without `start` and `end`, where 0.1.0 assumed the end of each quarter.
- `isEmptyBecauseOfErrors()` no longer counts a distributor that says it has no data for the period (error code 8) as a failure; `DistributorError::isNoData()` tells it.

### Added

- `SixPeriodSchedule`, the six-period calendar of 3.0TD and 6.1TD to 6.4TD from Circular CNMC 3/2020 (seasons, high and medium hours, and the differences of every territory), and `AccessTariff::schedule()` to pick the calendar of a tariff.
- `Authorization::$cups`.
- `AtomicStore`: give the 24 hour guard a store that can add a key only if absent (Laravel's `Cache::add()`, Redis, Memcached) and checking and recording become one step, so two workers starting the same query at once cannot both send it.
- Quarter-hourly labels in either possible convention: the end of each quarter (`00:15`..`24:00`) or the hour that ends followed by the minute the quarter starts (`01:00`..`24:45`). `QuarterHourConvention` tells which one an answer uses; quarters are placed on their real time in both, daylight saving days included, and an answer that cannot tell gets no intervals instead of a guess.

### Fixed

- Dumping a client (`var_dump`, `print_r`, `var_export`) no longer shows the login token when the token cache or the ledger store is one of yours that shows its values. `var_dump` and `print_r` of the client no longer show the account NIF either.
- The records (`Supply`, `ContractDetail`, the readings, `ReactiveEnergy`, `Authorization`, `PartnerUser`, `Group`, `DistributorError`) and `ApiResult` show their personal fields (CUPS, address, postal code, CAU, names, documents, email, `raw`) as `[hidden]` in `var_dump`, `print_r` and the dumpers that follow `__debugInfo`, such as Laravel's `dump()`. The properties still give the values.
- A `Nif` or a `Cups` no longer shows its value in `var_dump`, `print_r` or `var_export`, so the holder of a client from `forHolder()`, an `authorizedNif` and a supply in the arguments of a stack trace stay hidden. `value()`, string casts and `serialize()` still give it.
- The arguments recorded in stack traces no longer carry the body of an answer that is not valid JSON, the account NIF when the ledger store fails, a refused username, NIF or CUPS, nor the query inside the closures the client passes around.

- The 24 hour guard ignores a stored time more than ten minutes ahead of the clock, which could block a query for far longer than the window, and with an `AtomicStore` a held key always counts until the store expires it, so two workers cannot both take it back.
- `PublicSearchQuery` and `SelfConsumptionSearchQuery` keep a copy of their dates, so changing the `DateTime` they were given no longer makes `startDate` and `endDate` disagree with what is sent. Both properties are now `DateTimeImmutable`.

- A public query with something other than a `Community` case among its communities fails with `InvalidRequestException` instead of a `TypeError`.
- The validity dates of the authorization list carry a time of day and were read as null.
- An account without groups got an exception: Datadis answers the text `No groups`.
- Reactive data for a period without data gave one empty record instead of an empty result.

## [0.1.0] - 2026-09-30

First public release.

### Added

- `DatadisClient` for the private API, v1 and v2: supplies, distributors with supplies, contract detail, hourly and quarter-hourly consumption, maximum power and reactive energy (v2 only), with methods named after the Datadis endpoints.
- `forHolder()`, a client for one holder's supplies that sends the authorized NIF on every call.
- Authorization management, groups and partner calls, and `PublicApiClient` for the public open data API (unverified against real answers).
- Typed, immutable results with Datadis's own field names, every digit of every number kept, the raw rows and the distributor errors reported inside a 200.
- Exception taxonomy that tells whether a request may have reached Datadis, with personal data redacted.
- Token handling from the JWT expiry, shareable through any PSR-16 cache.
- PSR-18 transport with Guzzle by default, an opt-in retrying decorator for the calls where a repeat is harmless, and an optional guard for the 24 hour repetition rule.
- Daylight saving change days placed on the right hours, in Madrid and the Canary Islands.
- Helpers: month planning within the served window, access tariff recognition, the 2.0TD schedule, national holidays, territories, CUPS and NIF values (NIF control letter checked), personal data redaction.

[Unreleased]: https://github.com/lenorix/datadis-php-client/compare/v0.4.0...HEAD
[0.4.0]: https://github.com/lenorix/datadis-php-client/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/lenorix/datadis-php-client/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/lenorix/datadis-php-client/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/lenorix/datadis-php-client/releases/tag/v0.1.0
