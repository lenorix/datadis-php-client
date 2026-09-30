# Changelog

All notable changes to `lenorix/datadis-client` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). While the version is 0.x, a minor release may break the public API.

## [Unreleased]

## [0.1.0] - Unreleased

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
