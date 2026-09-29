# Changelog

All notable changes to `datadis-client` will be documented in this file.

## Unreleased

First version of the client.

- Private API v1 and v2: supplies, distributors with supplies, contract detail, hourly and quarter-hourly consumption, maximum power and reactive energy (v2 only).
- v1-only functionality: authorization management and the public open data API (unverified against real answers).
- Typed, immutable results that keep the raw rows and the distributor errors reported inside a 200.
- Exception taxonomy that tells whether a request may have reached Datadis, with personal data redacted.
- Token handling from the JWT expiry, shareable through any PSR-16 cache.
- PSR-18 transport with Guzzle by default, an opt-in retrying decorator for the calls where a repeat is harmless, and an optional guard for the 24 hour repetition rule.
- Daylight saving change days placed on the right hours, in Madrid and the Canary Islands.
- Helpers: month planning within the served window, access tariff recognition, the 2.0TD schedule, national holidays, territories, CUPS and NIF values, personal data redaction.
