# Design decisions

The architecture as built. Each decision states the reason so it can be revisited.

## Decisions taken with the maintainer

- **Both API versions are supported.** v2 is the default; v1 is available for every endpoint that has it. Functionality v2 lacks is included on its v1-style paths: authorization management and the public API. Reactive data and groups exist only in v2; the partner programme calls have no version suffix.
- **Every endpoint in the official documentation is covered**, including groups and the partner programme. Answers the documentation does not describe are returned raw.
- **No framework dependency.** The package must work without Laravel; applications configure it from their own settings with `fromArray()` and pass their HTTP client and cache. An architecture test keeps Laravel and Symfony classes out.
- **Guzzle, at its latest line, is the default transport**, but the client only type-hints PSR interfaces so any PSR-18 client can replace it. That agnosticism is kept only while it causes no bugs.
- **Decimals use `brick/math`** and are exposed as exact strings: every digit Datadis sends is kept, never rounded, padded to a minimum number of decimals per field (decided September 2026).

- **Names are Datadis's own** (decided September 2026). Methods are the endpoint names in camelCase (`getConsumptionData()`, `listAuthorization()`, `apiSearchAuto()`), parameters are the query parameter names (`startDate`, `endDate`, `authorizedNif`, and on the public queries `community`, `measurementType`, `fare`... in singular, like Datadis, even when they take several values), and DTO fields are the JSON keys exactly as Datadis sends them, odd casing included (`contractedPowerkW`, `municipioCode`, `codeDescription`). Values the client derives (intervals, `hourOfDay`, `openEnded`, the grouped reactive `periods`) have their own names.
- **v2 is the default version**, although every real capture so far came from v1 paths: it is the current API and the only one with reactive data, groups and distributor errors.
- **A client per holder** (`forHolder()`), since reading supplies of people who authorized the account is the professional use: a copy of the client that sends the holder's NIF on every supply and data call and refuses a different one. It is optional; `authorizedNif` per call still works.
- **No client interface.** Applications fake Datadis over HTTP in their tests; an interface would turn every new endpoint into a breaking change.
- **Two ways to build the client, kept apart**: the constructor for plain code and `fromArray()` for application settings. Their parameter names are part of the 1.0 API. The wiring shared with the public API lives in the internal `Http\Connection`.
- **Both entry points at the root**: `DatadisClient` for the private API and `PublicApiClient` for the public one; the public API's queries and records live in `PublicApi`.
- **Kept as they are:** tolerated answer shapes no source documents (marked `TOLERATED, NO SOURCE` in the code; a public row, alone or in a list, is only read as a record when it has a field of one), contract detail as a list like Datadis's answer, raw answers of the calls never captured (typed once a real answer is seen), and one time zone per client.

## Dependencies

- Runtime: PHP `^8.4`, `guzzlehttp/guzzle` `^8.2` with `guzzlehttp/psr7` `^3.1`, `brick/math` from `0.14.2` to `1.x` (the range the current Laravel accepts, so applications do not have to upgrade it), and the PSR interfaces: `psr/http-client`, `psr/http-factory`, `psr/http-message`, `psr/simple-cache`, `psr/clock`.
- Development: PHP 8.4 and 8.5 are both supported, and the suite fails on deprecations. Two transitive development packages have explicit floors in `require-dev` only (`phpdocumentor/reflection-docblock` 5.6.3, `sebastian/recursion-context` 7.0.1): earlier versions, which the lowest Pest allows, trigger PHP 8.5 deprecations. They do not affect what users install.
- The client depends only on `Psr\Http\Client\ClientInterface`, `RequestFactoryInterface`, `StreamFactoryInterface` and friends. Any PSR-18 client works (Symfony HttpClient, Laravel's `Http::buildClient()`, a test double).
- Guzzle specifics live in one small factory: `http_errors => false`, `decode_content => false`, redirects off, explicit timeouts. The suite runs with both the lowest and the latest allowed dependencies.

## Layers

1. **Transport**: sends PSR-7 requests, adds the mandatory headers, reads the whole body inside the same failure mapping (a streaming client transfers the body only when it is read), never throws on HTTP status by itself.
2. **Authentication**: `TokenProvider` obtains and caches the JWT (PSR-16 store optional, in-memory default), reads `exp`, refreshes once on 401 (and repeats the call, except a guarded query or a change, which are never sent twice).
3. **Endpoints**: one method per endpoint, taking value objects (`Cups`, `Nif`, `Month`) and validating the rest before any request leaves the machine. The data calls also take a listed `Supply` (`getConsumptionDataOf()` and the like), so its CUPS and codes are sent exactly as Datadis gave them.
4. **Decoding** (the internal `Decoding` namespace; `Data` holds only the results): turns envelopes into immutable DTOs, keeping `raw` and `distributorErrors`. Tolerant reader: accepts a bare list, both `installedCapacity`/`installedCapacityKW`, `accessFare`/`accesFare`, numeric strings, `""` as null.
5. **Helpers**: pure classes (month, hour label, CUPS, redactor, fingerprint, tariff-shape parser).

## Value objects

- `Month` (`YYYY/MM`): parse, format, arithmetic, ordering, sequences, the 24-month window check.
- `HourLabel` and `QuarterHourLabel`: `01:00`..`24:00` (or the quarters, `00:15`..`24:00` or `01:00`..`24:45`) to index, hour of the day and the interval they end on a given day. Reject other shapes.
- `Cups`: normalisation and shape check. Matching on the first 20 characters (`matches()`); `Data\SupplyMatcher` picks the supply of a CUPS from a list.
- `Nif`: normalisation, shape and control character (NIF/NIE modulo 23, CIF control digit or letter), checked by default because a mistyped NIF would be sent and refused; `checkControl: false` skips the check. Equality is `equals()` on every value object.
- `MeasurementType`: backed enum (`0` hourly, `1` quarter-hourly).
- Open values stay open: `pointType` int, `distributorCode` string, `obtainMethod` string with helper predicates.
- Energy and power values: exact decimal strings with a minimum scale, never floats and never rounded in derived data. A decimal JSON number never goes through a float: it is quoted before decoding (`ExactJson`), so `raw` keeps its exact text and every digit reaches the value.

## Results, not silent failures

- Every list endpoint returns a result object: records + `distributorErrors`. "Empty with distributor errors" is distinct from "empty".
- Decoding problems raise a dedicated exception. Never return an empty result to hide a failure.
- Rows with `consumptionKWh = null` are dropped individually and counted.
- Hourly rows keep source order; duplicates on DST days remain.

## Exceptions

Base `DatadisException` (extends `RuntimeException`) carrying: HTTP status (nullable), redacted detail excerpt, endpoint name, and `requestSent`.

`requestSent` is **only `false` for pre-flight failures**: configuration or parameter validation, and a login failure that happens before the data request. PSR-18 cannot tell "never sent" from "sent, no answer": `NetworkExceptionInterface` also covers read timeouts (Guzzle turns curl error 28 into a `ConnectException`), and a slow endpoint hitting a short timeout is the common case, not an edge case. So **any network exception on a data call means the outcome is unknown and is treated as possibly sent**.

| Exception | When | Retry |
|-----------|------|-------|
| `ConfigurationException` | missing credentials or base URL | no, thrown before any HTTP call |
| `AuthenticationException` | login 401/403, a 401 after the one re-login, or a 401 on a call that is not safe to repeat: a guarded query or a change (never sent again) | no |
| `AuthorizationException` | 403, or the 400 "no se encuentra autorizado" of contract detail and consumption | no, the caller's consent or stale codes |
| `RequestRejectedException` | 400 and other 4xx | never the identical call |
| `NoDataException` | 404, 204, empty body | caller decides |
| `RepetitionWindowException` | 429 | never, the window expires in 24 h |
| `ServiceUnavailableException` | 5xx | only unguarded endpoints |
| `TransportException` | anything the HTTP client throws, or a body that fails while being read: outcome unknown, `requestSent = true` | unguarded endpoints only; never automatically on guarded ones |
| `UninterpretableResponseException` | 200 with unusable body, missing keys, bad dates or numbers | not blindly |
| `PageLimitReachedException` | a walk through every page of a public search stopped at its limit with a full last page; thrown after every record read was yielded | go on from `nextPage` |

Anything other than a Datadis exception thrown while decoding a 200 body (date parse, decimal parse, type errors) is wrapped in `UninterpretableResponseException` at one boundary, so nothing escapes as a raw `TypeError`.

Redaction happens in the base class (shape-based), so a subclass that interpolates a CUPS still cannot leak it.

## Retries

The client does **not** embed job-level retry policy. It offers an opt-in decorator that, for the calls known to be safe to repeat only (login, supplies, distributors, contract detail, groups, the authorization list, the partner reads and the public API; an allowlist, so a call added later is not retried until it is classified), retries network exceptions and 502/503/504 with exponential backoff and equal jitter, honouring `Retry-After` (seconds or HTTP date) unless it asks for more than the maximum wait, in which case the answer is returned as is. It never retries 4xx nor a plain 500 (Datadis answers an empty 500 consistently for some supplies). The authorization changes and unlinking a partner user are never retried either, and after a 401 only the calls safe to repeat are sent again with a new token: a change could be applied twice, and a guarded query could cost it for 24 hours. On guarded endpoints (consumption, max power, reactive) it never retries automatically once the request may have been sent, including network exceptions. Sleep goes through an injectable callable so tests do not wait.

## The 24 h guard

`RequestFingerprinter` builds a stable key from the account and the query parameters in fixed order, leaving out the endpoint (maximum power and reactive data with the same parameters collide). `authorizedNif` is kept as `null` when omitted, so for consumption sent and omitted are different calls; for maximum power and reactive it is always left out, because the official manual does not key those on it. A `RequestLedger` (on one `LedgerStore`, or a PSR-16 cache wrapped as one) registers an attempt **before** sending and **keeps it** after any failure that may have reached Datadis, including network exceptions. It forgets the attempt only for pre-flight failures (validation, login failure before the data request). The HMAC key is supplied by the caller so keys cannot be reversed to a CUPS. Without a ledger, the client makes one in memory with a random key: it protects that process only, but the rule Datadis spells out is never broken by a single client repeating a query.

### How the guard is wired

`DatadisClient` takes a `RequestLedger` (its own in memory when none is given) and hands it to an internal `RepetitionGuard`; which endpoints are guarded is a property of the internal `Endpoint` enum. For consumption, max power and reactive it refuses locally (a `RepetitionWindowException` with `requestSent = false` and no HTTP status) a query attempted in the window, records the attempt before sending, and forgets it only for failures with `requestSent = false`. Without a ledger the client remembers its own attempts in memory, so a long-lived process is protected, but each process starts empty. The ledger only sees attempts made through a store it shares, so every process using the same account must use the same store. Checking and recording are two separate store calls with a plain PSR-16 store (it has no add-if-absent), so two workers racing on the same query can both send it; given an `AtomicLedgerStore` (one store that can also add a key only if absent), they are one call and only one worker sends. The ledger takes a single store for all of it: a pair of stores (a cache and a separate atomic one, as before 0.5.0) could point at different backends, and then a forget after an unsent failure missed the held key and the time read back was wrong. If the store cannot be read or written the query is not sent (`LedgerUnavailableException`).

Only the ledger decides whether a query may go, and the library never hands out a key for a caller to rebuild or compare, since one rebuilt outside the client could drift from what it sends. Everything that touches the ledger goes through the same private query builders as the calls: a call claims its key (a local refusal carries `lastAttemptAt` and `availableAt`), the `...BlockedUntil()` lookups read it without claiming, and `remember...()` record an earlier attempt under it. A `LedgerEvent` carries only the ledger's opaque store key, for a history. An application moving from a record of its own tells the ledger what it sent with `remember...()`, which build each query with the same private builders as the calls (so the key cannot drift), keep it for what is left of its window and keep the newest attempt; if it cannot tell what it sent, it keeps its old check for one window beside the ledger.

The window is 24 h plus 10 min by default (`windowSeconds` changes it, never below 24 h). A daily sync cannot repeat a query every day under any window of 24 h or more, since Datadis itself refuses it whenever a run starts earlier than the day before. So the daily calls (`getLatest...Of()`, `MonthPlanner::latest()`) pick the range by the parity of the Madrid civil day (days since 1970, not day of month or year, which repeat parity across month and year ends): `[current, current]` and `[previous, current]` alternate, and each comes back about every 48 h. A second run on the same day gets the same range and is refused: falling back to the other range would send tomorrow's query today and block tomorrow's run. The same range comes back two civil days later, so two runs inside a fixed band of Madrid hours (04:00 to 22:00) are at least 29 hours apart; a job fixed in UTC drifts by an hour against the Madrid calendar at each clock change and can run twice on one Madrid day or skip one, so the README asks for a Madrid schedule. A two-month answer is split by month on the consumer side; the client does not cut it, so `raw` stays the answer as received. Reactive data shares the key with maximum power, so it has no daily call.

A data request that got a 401 was sent. For a guarded query the client drops the token and does not send it again: whether Datadis counted the rejected request is unknown, and a repeat would cost the query for 24 hours. The failure is an `AuthenticationException` with `requestSent = true`, and the next call logs in again. For the other calls it logs in once more and repeats the call; if that login fails, the failure is still reported with `requestSent = true`, never as the unsent login failure. The token is renewed two minutes before it expires, so a 401 on a data call is rare.

## Time parsing

Every row keeps the raw `time` string. Parsing depends on what was requested:

- Hourly consumption (`measurementType=0`): strict `HourLabel` (`01:00`..`24:00`, end of interval, 1 h wide).
- Quarter-hourly consumption (`measurementType=1`): 15 minutes wide, `HH:MM` on a quarter, in one of two conventions detected per answer (`QuarterHourConvention`): the end of each quarter (`00:15`..`24:00`) or the hour that ends plus the minute the quarter starts (`01:00`..`24:45`). An answer that shows neither gets no intervals (UNVERIFIED, no source documents the format).
- Max power: `date` + `time` is an **instant** (for example `09:45`), parsed by a separate instant parser that still understands `24:00`.
- An unrecognised shape yields a null index/instant and a flag on that row (for example the `00:00` glitch). It never fails the whole response.

## Configuration

Immutable `DatadisConfig`: username, password, base URL (default `https://datadis.es`, HTTPS only), user agent, connect timeout and one overall timeout (PSR-18 has no per-request timeouts, so login and data calls share it). The password and the username (the account's NIF, read with `username()`) are kept in closures so `var_dump`, `print_r`, `var_export` and `serialize` cannot expose them.

## Things deliberately not done

- No persistence, queues, scheduling or logging inside the package.
- No supply-to-distributor mapping beyond exposing codes.
- No automatic multi-month splitting inside a call; a helper produces a plan of single-month requests and the caller decides.
- No web-portal endpoints.
