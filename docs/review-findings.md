# Review findings pending a fix

Found by an independent review in M6 (all confirmed by running code unless noted). Each one gets a failing test first, then the fix. Remove an entry when it is fixed.

1. ~~Guard keeps a never-sent query~~ fixed: the token store is an optimisation that never breaks a call, poisoned cached tokens are dropped, `Transport` wraps anything the HTTP client throws, and ledger store failures raise `LedgerUnavailableException` before sending.
2. ~~Guard silently disabled~~ fixed: numeric string timestamps are read, `set()` returning false is an error, and stored timestamps are checked against the clock. Still true and documented: `lastAttempt` + `record` is not atomic across workers.
3. ~~Autumn DST intervals are wrong~~ fixed: labels resolve to every instant they can name (offset in effect just before the end of the interval) and the client picks by order of appearance; widths use elapsed time. A label in the skipped hour or a third repetition has no interval.
4. ~~Retry-After date parsed in the host time zone~~ fixed.
5. ~~`searchAll` repeats generator keys~~ fixed.
6. ~~Unbounded decimal exponents~~ fixed: numeric strings are limited to 64 characters and a three digit exponent; reactive decoding has the same boundary as the other endpoints.
7. ~~Unexpected shapes become empty results~~ fixed: every known shape of reactive and distributor answers is read (including lists of codes and `reactiveEnergy` as a list), anything else is an `UninterpretableResponseException`, and a single `distributorError` object is kept.
8. ~~Window checked in the client zone~~ fixed: the window is judged on the Madrid calendar (an assumption, marked UNVERIFIED).
9. ~~CUPS and NIF in stack trace arguments~~ fixed with `#[SensitiveParameter]` on queries, requests and tokens. `Cups` and `Nif` objects still appear as objects in trace arguments.
10. ~~Redactor gaps~~ fixed: separators and labels glued to NIF/NIE/CIF are redacted.
11. ~~Latin-1 bodies~~ fixed: a body that is not UTF-8 is read as Windows-1252.
12. ~~Docblocks~~ fixed.

## Other M6 work left

- ~~Coverage target~~ done: 100 % lines, enforced at 98 % minimum by `composer test-coverage` and in CI (pcov). PHPStan runs at level max in CI through `phpstan.neon.dist`.
- Mutation testing: the first parallel run reported 100 %, which was false. A full run (`--mutate --parallel --everything --clear-cache`) found 335 surviving mutants; tests were added for every one that exposed untested behaviour (validation of every endpoint, authorizedNif on every endpoint, status boundaries, PSR-16 key validity, token lifetime edges, jitter, paging, fingerprint stability). The plugin's test selection proved unreliable here: some mutants it reports as surviving are killed by existing tests when applied by hand, so the survivors that matter were checked by applying each mutation and running the whole suite. What remains is equivalent: redundant literals (JSON depth, the two day window around transitions, PSR-16 key lengths below 64), casts of values that already have the type, `http_errors`/`allow_redirects` (Guzzle's PSR-18 `sendRequest` forces both), `floor` versus `ceil` to detect whole floats, the maximum page default, and constant table items reported as uncovered because constants are not executable lines.
- ~~README and CHANGELOG~~ done; the README examples were run against the fake HTTP client.
- Lowest dependencies (`composer update --prefer-lowest`: Pest 4.0.0, PHPUnit 12.3, Guzzle 8.2.0, brick/math 1.0.0) pass the whole suite.

## Second review

Found by a second independent review after the fixes above. All fixed with a failing test first unless stated.

1. Reading the response body happened outside `Transport`, so a streaming client whose body failed while being read escaped the exception contract, and during login it left a never-sent query blocked by the guard for 24 hours. `Transport` now reads the whole body inside the same failure mapping and returns it in memory.
2. Decoded answers (rows, envelopes) are marked `#[SensitiveParameter]`, so CUPS in a failing answer no longer reach stack trace arguments.
3. Dumping the client showed the live token held by the in-memory cache. The cache keeps its items inside a closure (hidden from `var_export`) and has a `__debugInfo` (hidden from `var_dump` and `print_r`). The account username stays visible: it is a public property of `DatadisConfig`.
4. `MonthPlanner` judged "now" in the zone it was given while the client judges the Madrid calendar. `Month::current()` now reads the Madrid calendar and both use it.
5. The README described the decimal scales wrongly; the unused scale constants are gone.
6. A top-level `{}` passed as "no data". It is now an `UninterpretableResponseException` everywhere, the public API included.
7. A reactive list with no usable entry is an error like on the other endpoints; a `distributorError` sent as text is kept; the README says which exceptions are not `DatadisException`; the Ceuta and Melilla docblock matches the table.
8. Not changed, on purpose: quarter-hourly data is not refused locally for any point type (a real type 5 supply answers an empty list instead of refusing; see [open-questions.md](open-questions.md)).

## Third review (source and tests, September 2026)

Applied:

1. `findSupply()` reports a distributor failure instead of answering "not your supply"; the distributors list reads a 404 and a null list as empty, like the supplies list; big numeric ids stay exact; `fromArray()` keeps the password as given and reads dashed setting names.
2. `TransportException` is no longer a kind of `ServiceUnavailableException`, so catching "try again later" does not also retry timeouts of guarded calls.
3. Readings carry `hourOfDay`, so quarter-hourly data works with the tariff period mapper; the README had passed the quarter index (0-95) to it.
4. The docs no longer say a month not yet published is a `NoDataException`: it is an empty result.
5. Calls that take a listed `Supply` (`getConsumptionDataOf()` and the like).
6. Endpoints are one internal enum; retries are an allowlist; the 24 hour guard is its own internal class; classes outside the public API are `@internal`; the user agent has no version to go stale.
7. Tests: filler and duplicates removed, answers aligned with the real ones, property oracles made independent, and whole flows added (a supply found by its 20 character CUPS, the 24 hour token, the guard across a 401, retries through the client).

Decided with the maintainer afterwards (see [design-decisions.md](design-decisions.md)): Datadis's own names for methods, parameters and fields; v2 as the default; no client interface; tolerated shapes, contract detail as a list, raw answers of uncaptured calls and one time zone per client kept as they are.

Also decided: a client per holder (`forHolder()`).

Done since: the decoding helpers moved out of `Data` into the internal `Decoding` namespace.

Still open: `PublicApi\PublicApi` repeating its namespace, and merging the constructor with `fromArray()`.

## Bugs of other implementations checked

The mistakes found in other Datadis clients (see the "mistakes to avoid" notes in [quirks-and-rules.md](quirks-and-rules.md) and [design-decisions.md](design-decisions.md)) were checked one by one against this package, and each is covered by a test: retrying 429, guessing parameter variants, placing `24:00` on the same date, reading `obtainMethod` as `R`, treating `measurementType` as consumption/generation, swallowing decoding errors, reading an `hour` field, the `accesFare` spelling, mislabelled gzip, a blocked default user agent, sending `authorizedNif` unnormalised or for the account itself, comparing dates as strings, floats in exponent notation, the two year window computed as "now minus two years", short timeouts, hand-built query strings, and treating a failed transfer as never sent. The extra `00:00` row some distributors send is kept and flagged rather than dropped; the README tells callers to decide before summing.
