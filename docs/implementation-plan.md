# Implementation plan

Namespace `Lenorix\DatadisClient`. Every milestone follows TDD (red, green, refactor), ends with the full suite green and Pint clean, and is one commit. Test files mirror `src/` under `tests/Unit`, `tests/Feature` (client against a fake PSR-18) and `tests/Property` (Eris). Design rationale lives in [design-decisions.md](design-decisions.md).

## Decisions taken for this plan (revisit on request)

- The client supports **v1 and v2** (`ApiVersion` enum, default v2). v1 differs only in path (no `-v2` suffix) and in returning a bare JSON list without `distributorError`; reactive is v2 only. Functionality that exists only in v1 is **included**: authorization endpoints and the public API (UNVERIFIED, synthetic fixtures).
- PSR-18 agnosticism is kept only while it causes no bugs; Guzzle is the default and required.
- Runtime dependencies: `psr/http-client`, `psr/http-factory`, `psr/http-message`, `psr/simple-cache`, `psr/clock`, `brick/math`, `guzzlehttp/guzzle` (default transport). Any PSR-18 client can replace Guzzle because the client only type-hints the PSR interfaces.
- Eris works inside Pest 4 with `uses(Eris\TestTrait::class)` (smoke-tested: passing properties pass, failing ones fail and print a seed). Iterations are set with `->limitTo()` through a helper reading our own env var; `ERIS_ITERATIONS` does not exist in Eris. Reproduce with `ERIS_SEED=<seed> vendor/bin/pest --filter ...`.
- PSR-18 cannot tell "never sent" from "sent, no answer" (read timeouts are network exceptions). Network exceptions on guarded endpoints are treated as possibly sent. See [design-decisions.md](design-decisions.md).
- Decimals are `brick/math` `BigDecimal` exposed as strings with fixed scale (energy 3, power 2) plus the raw JSON value.

## M0 Foundations

1. **Fix the test gate first.** Bare `vendor/bin/pest` currently ran no tests (only a coverage warning), likely because of the `<coverage><report>` block in `phpunit.xml.dist`. Move coverage reporting to the `test-coverage` script only, bump the schema away from 10.3, and make `composer test` fail when zero tests run. Verify the summary shows a test count. Check exit codes without pipes (or `$pipestatus[1]` in zsh).
2. Remove scaffolding (`src/DatadisClientClass.php`, `tests/ExampleTest.php`). Keep `tests/ArchTest.php` and extend it.
3. `composer.json`: read `git diff composer.json` first (Eris in require-dev and the license change are uncommitted and must be kept). Add the runtime dependencies above; require-dev keeps Pest, Eris, Pint; drop `spatie/ray` only if unused.
4. `tests/Pest.php`: helper `datadisFixture(string $name): string` (`fixture()` is reserved by Pest), `uses(TestTrait::class)` for `tests/Property`, and a `pbtIterations()` helper feeding `limitTo()` from our own env var `DATADIS_PBT_ITERATIONS`.
5. `tests/Support/FakeHttpClient` (implements `ClientInterface`): queue of canned responses or closures, records every request, fails the test on an unexpected request. `tests/Support/Responses` builders using Guzzle PSR-7.
6. `tests/Fixtures/README.md` with the provenance table. Add first synthetic fixtures as each endpoint is built.
7. `.github/workflows`: keep matrix; add Pint check. `CHANGELOG.md` entry.
8. Commit: "Add project documentation and testing foundations".

## M1 Pure value objects and helpers (no HTTP)

| Class | Behaviour to test first |
|-------|-------------------------|
| `Month` | `fromString('2025/03')`, `format()`, `fromDate()`, `addMonths()`, `diffInMonths()`, comparison, invalid inputs (`2025-03`, `2025/3`, `2025/13`, day-level). Window: `isWithinHistory(now)` (24 months, boundary month refused), `isFuture(now)`. `range(from, to)` and `chunk`. |
| `HourLabel` | strict hourly parse `01:00`..`24:00`; `index()` 0-23; `intervalStart/End(date, DateTimeZone)` handling `24:00` as next-day midnight and DST via the zone; rejects `00:00`, `25:00`, `1:5`, `1:60`. |
| `QuarterHourLabel` | quarter-hourly `HH:MM` on a quarter, end-of-interval, 15 minutes wide (UNVERIFIED format). |
| `TimeInstant` | max-power `date` + `time` as an instant, understands `24:00`, explicit `DateTimeZone`. |
| `Cups` | normalise (trim, uppercase), shape validation (20 chars, optional 2-char suffix), `base()` = first 20 chars, `matches()`. Idempotent. |
| `MeasurementType` | backed enum `Hourly = '0'`, `QuarterHourly = '1'`. |
| `Nif` | trim + uppercase normalisation, `sameAs()`. Shape check lenient (NIF, NIE, CIF). |
| `PersonalDataRedactor` | replaces CUPS, NIF, NIE, CIF by shape with `[redacted]`, `excerpt(string, max)` collapses whitespace and caps length. Total function. |
| `Decimal` helper | float/int/numeric-string to scale-N string, half-up, exponent notation safe, rejects NaN/INF/bool. |

Properties (Eris): month format/parse round-trip; `addMonths` inverse; chunks cover the range with no overlap; hour index bijection over 1..24 and null otherwise; `intervalEnd - intervalStart = 1 h` on normal days and consistent on DST days; redactor never leaves an identifier-shaped substring and is idempotent; `Cups` normalisation idempotent; decimal conversion equals `BigDecimal::of` for exponent forms.

Commit: "Add month, hour label, CUPS, decimal and redaction helpers".

## M2 Exceptions and response classification

- `DatadisException` (abstract) with `httpStatus`, `detail` (redacted excerpt), `endpoint`, `requestSent`; subclasses per [design-decisions.md](design-decisions.md).
- `ResponseClassifier`: PSR-7 response to either a decoded JSON value or the right exception, applying the status table in [quirks-and-rules.md](quirks-and-rules.md): 200 non-JSON, empty body, 204, 400 `text/plain`, 401, 403, 404, 429, 5xx, plus error-body shapes (text, Spring JSON, `{"message"}`). Detects mislabelled gzip by magic bytes and inflates.
- Tests: table-driven over the status/body matrix; redaction of echoed CUPS/NIF; property: any status 100-599 and any body bytes either return decoded JSON or throw a `DatadisException`, never another type.

Commit: "Add exception taxonomy and HTTP response classification".

## M3 Configuration, transport and authentication

- `DatadisConfig` (readonly): credentials, base URL, user agent, timeouts. `__debugInfo` hides the password. Validation throws `ConfigurationException` before any request.
- `RequestFactory`: builds GET requests with stripped nulls, `http_build_query`, headers (`Accept`, `Accept-Encoding: identity`, `User-Agent`, `Authorization`).
- `GuzzleClientFactory::create(config)`: `http_errors=false`, `decode_content=false`, redirects off, timeouts. Only file allowed to reference Guzzle.
- `TokenProvider`: login POST form body, token cleaning and HTML/JSON guard, JWT `exp` decoding, PSR-16 store (in-memory default), PSR-20 clock, skew and fallback TTL, `invalidate()`. Login is done before the data request, so a login failure (401/403 to `AuthenticationException`, network exception to `TransportException`) has `requestSent = false` for the data call. A network exception on a data call has `requestSent = true` (outcome unknown).
- Tests: token cached across calls and not re-fetched before expiry, refreshed after expiry with a fake clock, exactly one re-login on a 401 and then failure, no credentials in the URL, password and token never appear in exception messages or `__debugInfo`, headers present on every request.

Commit: "Add configuration, transport and token handling".

## M4 Endpoints, DTOs and decoding

For each of the six v2 endpoints: request parameter object (validates required fields, month window, CUPS shape, measurement type), fixture(s), decoder, DTO, and client method. Order: supplies, distributors, contract detail, consumption, max power, reactive.

- Result wrapper `ApiResult<T>`: `records`, `distributorErrors`, `isEmptyBecauseOfErrors()`, `raw`.
- Time handling per [design-decisions.md](design-decisions.md): every row keeps the raw `time`; consumption rows are parsed by the requested `MeasurementType` (hourly strict, quarter-hourly assumed and marked UNVERIFIED); max-power rows use `TimeInstant`. An unrecognised shape gives a null index/instant and a flag on that row, never a failure of the whole response.
- DTOs (`final readonly`): `Supply`, `ContractDetail`, `ConsumptionReading`, `MaxPowerReading`, `ReactiveEnergy`, `DistributorError`. Each keeps `raw` and exposes typed values (dates parsed, open-ended `""` to null, decimals as scaled strings, `obtainMethod` string with `isReal()`/`isEstimated()`).
- `ApiVersion` enum (`V1`, `V2`) selects the path suffix and the decoder envelope handling; the same DTOs serve both versions. Calling reactive with `V1` throws `UnsupportedOperationException` before any request.
- `DatadisClient` facade: `supplies(?Nif)`, `distributors(?Nif)`, `contractDetail(Cups, string $distributorCode, ?Nif)`, `consumption(...)`, `maxPower(...)`, `reactive(...)`, plus `findSupply(Cups, ...)` implementing the matching rule (20-char base, open contract first, else latest start).
- `authorizedNif` omitted when it equals the account (normalised comparison).
- Tests per endpoint: request path and query exactly as documented, success, empty, `distributorError` only, mixed, null-heavy rows, tolerant keys (`accesFare`, `installedCapacityKW`), numeric strings, malformed rows raising `UninterpretableResponseException` only, all-rows-unusable case, DST 23/25 hour fixtures preserved in order, `00:00` glitch row flagged.
- Properties: random valid rows decode with `raw` intact; random junk in any field only ever throws `DatadisException`; decimals never become floats.

Commit per two or three endpoints, or one commit for the milestone if the diff stays reviewable.

## M4b v1-only endpoints (UNVERIFIED, synthetic fixtures)

- Authorization: `newAuthorization(Nif $authorizedNif, ?Month $from, ?Month $to, Cups ...$cups)`, `cancelAuthorization(Nif, Cups ...)`, `listAuthorizations(Nif $owner)` returning `Authorization` DTOs (`id`, `ownerDocument`, `requesterDocument`, `status`, `validityDateStart`, `validityDateEnd`, `distributorCodeFather`). Array parameters are sent as repeated `cups` keys or `cups[]`: the wire form is unknown, so it is one small isolated encoder.
- Public API: `PublicApi` class (no token) with `search`, `sumSearch`, `searchAuto`, `sumSearchAuto`; typed query object (dates `YYYY/MM/DD`, `page` from 0, `pageSize` up to 2000, mandatory `community`, code lists as enums or validated strings); responses decoded tolerantly (`mi1`..`mi25` kept as raw plus a typed accessor) because the shape is unknown.
- Every method documents that its behaviour is unverified.

Commit: "Add v1-only authorization and public API endpoints".

## M5 Consumer utilities

- `AccessFareParser` + `AccessTariff` enum (2.0TD, 3.0TD, 6.1TD-6.4TD) with period counts. Property: total, normalisation-invariant, null on contradiction. Cross-check with `contractedPowerkW` count in `ContractDetail::tariff()`.
- `RequestFingerprint` (HMAC, fixed order, `authorizedNif` null-preserving, account included) and optional `RequestLedger` on PSR-16 with register-before-send semantics: the attempt is kept after any failure that may have reached Datadis (including network exceptions) and forgotten only for pre-flight failures. Properties: deterministic, differs when any parameter differs.
- `RetryingClient` (PSR-18 decorator): for unguarded endpoints only, retries network exceptions and 502/503/504 with injectable sleeper, jitter and `Retry-After` clamping. Never retries 4xx, and never automatically retries guarded endpoints once the request may have been sent (network exceptions included). Test: a network exception on consumption sends exactly one request and keeps the ledger entry.
- `MonthPlanner`: turns a wanted range into single-month requests inside the allowed window, skipping months before `validDateFrom` or after `min(validDateTo, today)`.
- Optional and only if agreed: `Territory` from postal code, 2.0TD fixed period mapper, national holiday list. Marked out of the first release unless the maintainer wants them.

Commit: "Add tariff parsing, request fingerprint, retry decorator and month planner".

## M6 Review and hardening (no new features)

1. Run the whole suite in random order, 3 times with different seeds.
2. Read every class end to end against [quirks-and-rules.md](quirks-and-rules.md) and [api-reference.md](api-reference.md).
3. Mutation checks: Pest mutate plugin on `src/`, plus manual breakage of the four invariants (no retry on 429, `authorizedNif` omission, hour mapping, redaction).
4. Extra Eris campaigns with large iteration counts (`ERIS_ITERATIONS`) on decoders, classifier and helpers; convert every counterexample into an example test.
5. Grep for personal data patterns and forbidden project names.
6. README (usage, custom PSR-18 client, error handling, the 24 h rule), CHANGELOG, workflow check.
7. Fix every finding, repeat 1-5 until clean, then final commit "Harden client after review".

## Definition of done

All tests green including properties, Pint clean, no personal data, README examples run, every public method documented with the endpoint and evidence level of its behaviour, open questions listed in [open-questions.md](open-questions.md).
