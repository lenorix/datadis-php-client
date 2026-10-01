# Changelog

All notable changes to `lenorix/datadis-client` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). While the version is 0.x, a minor release may break the public API.

## [Unreleased]

Checked against a new set of real answers (October 2026): the v2 paths, the authorization list, the partner calls and the public API.

### Changed

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

- The 24 hour guard ignores a stored time more than ten minutes ahead of the clock, which could block a query for far longer than the window, and with an `AtomicStore` a held key always counts until the store expires it, so two workers cannot both take it back.
- `PublicSearchQuery` and `SelfConsumptionSearchQuery` keep a copy of their dates, so changing the `DateTime` they were given no longer makes `startDate` and `endDate` disagree with what is sent. Both properties are now `DateTimeImmutable`.

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

[Unreleased]: https://github.com/lenorix/datadis-php-client/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/lenorix/datadis-php-client/releases/tag/v0.1.0
