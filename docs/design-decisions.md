# Design decisions

The architecture as built. Each decision states the reason so it can be revisited.

## Decisions taken with the maintainer

- **Both API versions are supported.** v2 is the default; v1 is available for every endpoint that has it. Functionality v2 lacks is included on its v1-style paths: authorization management and the public API. Reactive data and groups exist only in v2; the partner programme calls have no version suffix.
- **Every endpoint in the official documentation is covered**, including groups and the partner programme. Answers the documentation does not describe are returned raw.
- **No framework dependency.** The package must work without Laravel; applications configure it from their own settings with `fromArray()` and pass their HTTP client and cache. An architecture test keeps Laravel and Symfony classes out.
- **Guzzle, at its latest line, is the default transport**, but the client only type-hints PSR interfaces so any PSR-18 client can replace it. That agnosticism is kept only while it causes no bugs.
- **Decimals use `brick/math`** and are exposed as scaled strings.

## Dependencies

- Runtime: PHP `^8.4`, `guzzlehttp/guzzle` `^8.2` with `guzzlehttp/psr7` `^3.1`, `brick/math` from `0.14.2` to `1.x` (the range the current Laravel accepts, so applications do not have to upgrade it), and the PSR interfaces: `psr/http-client`, `psr/http-factory`, `psr/http-message`, `psr/simple-cache`, `psr/clock`.
- The client depends only on `Psr\Http\Client\ClientInterface`, `RequestFactoryInterface`, `StreamFactoryInterface` and friends. Any PSR-18 client works (Symfony HttpClient, Laravel's `Http::buildClient()`, a test double).
- Guzzle specifics live in one small factory: `http_errors => false`, `decode_content => false`, redirects off, explicit timeouts. The suite runs with both the lowest and the latest allowed dependencies.

## Layers

1. **Transport**: sends PSR-7 requests, adds the mandatory headers, reads the whole body inside the same failure mapping (a streaming client transfers the body only when it is read), never throws on HTTP status by itself.
2. **Authentication**: `TokenProvider` obtains and caches the JWT (PSR-16 store optional, in-memory default), reads `exp`, refreshes once on 401.
3. **Endpoints**: one method per endpoint, taking value objects (`Cups`, `Nif`, `Month`) and validating the rest before any request leaves the machine. The data calls also take a listed `Supply` (`consumptionOf()` and the like), so its CUPS and codes are sent exactly as Datadis gave them. `DatadisClientInterface` lists the calls, so applications can stand in for the client in their tests.
4. **Decoding**: turns envelopes into immutable DTOs, keeping `raw` and `distributorErrors`. Tolerant reader: accepts a bare list, both `installedCapacity`/`installedCapacityKW`, `accessFare`/`accesFare`, numeric strings, `""` as null.
5. **Helpers**: pure classes (month, hour label, CUPS, redactor, fingerprint, tariff-shape parser).

## Value objects

- `Month` (`YYYY/MM`): parse, format, arithmetic, chunking, 24-month window check, no-future check.
- `HourLabel`: `01:00`..`24:00` to index and interval start/end in a given `DateTimeZone`. Rejects other shapes.
- `Cups`: normalisation and shape check. Matching on the first 20 characters.
- `MeasurementType`: backed enum (`0` hourly, `1` quarter-hourly).
- Open values stay open: `pointType` int, `distributorCode` string, `obtainMethod` string with helper predicates.
- Energy and power values: decimal strings with explicit scale, never floats in derived data. The raw float from JSON is kept in `raw`.

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
| `AuthenticationException` | login 401/403, or 401 after the one re-login | no |
| `AuthorizationException` | 403, or the 400 "no se encuentra autorizado" of contract detail and consumption | no, the caller's consent or stale codes |
| `RequestRejectedException` | 400 and other 4xx | never the identical call |
| `NoDataException` | 404, 204, empty body | caller decides |
| `RepetitionWindowException` | 429 | never, the window expires in 24 h |
| `ServiceUnavailableException` | 5xx | only unguarded endpoints |
| `TransportException` (a `ServiceUnavailableException`) | PSR-18 network exception, outcome unknown, `requestSent = true` | unguarded endpoints only; never automatically on guarded ones |
| `UninterpretableResponseException` | 200 with unusable body, missing keys, bad dates or numbers | not blindly |

Anything other than a Datadis exception thrown while decoding a 200 body (date parse, decimal parse, type errors) is wrapped in `UninterpretableResponseException` at one boundary, so nothing escapes as a raw `TypeError`.

Redaction happens in the base class (shape-based), so a subclass that interpolates a CUPS still cannot leak it.

## Retries

The client does **not** embed job-level retry policy. It offers an opt-in decorator that, for **unguarded** endpoints only (supplies, distributors, contract detail, login), retries network exceptions and 502/503/504 with exponential backoff and equal jitter, honouring `Retry-After` (seconds or HTTP date) unless it asks for more than the maximum wait, in which case the answer is returned as is. It never retries 4xx nor a plain 500 (Datadis answers an empty 500 consistently for some supplies). The authorization changes are never retried either. On guarded endpoints (consumption, max power, reactive) it never retries automatically once the request may have been sent, including network exceptions. Sleep goes through an injectable callable so tests do not wait.

## The 24 h guard (optional)

`RequestFingerprint` builds a stable key from account, endpoint-agnostic query parameters in fixed order, with `authorizedNif` kept as `null` when omitted (sent and omitted are different calls). An optional `RequestLedger` (PSR-16 backed) registers an attempt **before** sending and **keeps it** after any failure that may have reached Datadis, including network exceptions. It forgets the attempt only for pre-flight failures (validation, login failure before the data request). The HMAC key is supplied by the caller so keys cannot be reversed to a CUPS.

### How the guard is wired

`DatadisClient` takes an optional `RequestLedger`. For consumption, max power and reactive it refuses locally (a `RepetitionWindowException` with `requestSent = false` and no HTTP status) a query attempted in the window, records the attempt before sending, and forgets it only for failures with `requestSent = false`. Without a ledger the client sends whatever it is asked. The ledger only sees attempts made through a store it shares, so every process using the same account must use the same PSR-16 store. Checking and recording are two separate store calls (PSR-16 has no add-if-absent), so two workers racing on the same query can both send it; serialise such work per account if that matters. If the store cannot be read or written the query is not sent (`LedgerUnavailableException`).

A data request that got a 401 was sent: if logging in again then fails, the failure is reported as an `AuthenticationException` with `requestSent = true`, never as the unsent login failure.

## Time parsing

Every row keeps the raw `time` string. Parsing depends on what was requested:

- Hourly consumption (`measurementType=0`): strict `HourLabel` (`01:00`..`24:00`, end of interval, 1 h wide).
- Quarter-hourly consumption (`measurementType=1`): assumed end-of-interval, 15 minutes wide, `HH:MM` on a quarter (UNVERIFIED, no source documents the format).
- Max power: `date` + `time` is an **instant** (for example `09:45`), parsed by a separate instant parser that still understands `24:00`.
- An unrecognised shape yields a null index/instant and a flag on that row (for example the `00:00` glitch). It never fails the whole response.

## Configuration

Immutable `DatadisConfig`: username, password, base URL (default `https://datadis.es`, HTTPS only), user agent, connect timeout and one overall timeout (PSR-18 has no per-request timeouts, so login and data calls share it). The password is kept in a closure so `var_dump`, `print_r`, `var_export` and `serialize` cannot expose it.

## Things deliberately not done

- No persistence, queues, scheduling or logging inside the package.
- No supply-to-distributor mapping beyond exposing codes.
- No automatic multi-month splitting inside a call; a helper produces a plan of single-month requests and the caller decides.
- No web-portal endpoints.
