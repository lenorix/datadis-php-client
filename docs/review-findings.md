# Review findings pending a fix

Found by an independent review in M6 (all confirmed by running code unless noted). Each one gets a failing test first, then the fix. Remove an entry when it is fixed.

1. ~~Guard keeps a never-sent query~~ fixed: the token store is an optimisation that never breaks a call, poisoned cached tokens are dropped, `Transport` wraps anything the HTTP client throws, and ledger store failures raise `LedgerUnavailableException` before sending.
2. ~~Guard silently disabled~~ fixed: numeric string timestamps are read, `set()` returning false is an error, and stored timestamps are checked against the clock. Still true and documented: `lastAttempt` + `record` is not atomic across workers.
3. ~~Autumn DST intervals are wrong~~ fixed: labels resolve to every instant they can name (offset in effect just before the end of the interval) and the client picks by order of appearance; widths use elapsed time. A label in the skipped hour or a third repetition has no interval.
4. ~~Retry-After date parsed in the host time zone~~ fixed.
5. ~~`searchAll` repeats generator keys~~ fixed.
6. ~~Unbounded decimal exponents~~ fixed: numeric strings are limited to 64 characters and a three digit exponent; reactive decoding has the same boundary as the other endpoints.
7. ~~Unexpected shapes become empty results~~ fixed: every known shape of reactive and distributor answers is read (including lists of codes and `reactiveEnergy` as a list), anything else is an `UninterpretableResponseException`, and a single `distributorError` object is kept.
8. **Window checked in the client zone.** With Atlantic/Canary at 23:30 on the last day of a month, the history window is one month off compared with Madrid time (assuming Datadis evaluates in Madrid time).
9. ~~CUPS and NIF in stack trace arguments~~ fixed with `#[SensitiveParameter]` on queries, requests and tokens. `Cups` and `Nif` objects still appear as objects in trace arguments.
10. Nit: redactor misses `12345678-Z`, `X-1234567-L` and NIFs glued to letters.
11. Nit: one Latin-1 byte makes a whole 200 uninterpretable (consider `JSON_INVALID_UTF8_SUBSTITUTE`).
12. Nit: docblocks of `ServiceUnavailableException` (login non-token answer) and `DatadisClient` ("nothing retries a request that may have been sent", but a 401 is repeated once by design).

## Other M6 work left

- Coverage target: at least 98 %, ideally 99-100 % (maintainer requirement). Measured at 96.3 % before these fixes; gaps in `InMemoryCache`, `SystemClock`, `DistributorCodes`, `SelfConsumptionSearchQuery::withPage`, `RequestFactory`, `Envelope`, `PublicApi`, `RetryingClient`, `Nif`, `PersonalDataRedactor`. Then enforce it with `--coverage --min=98` in CI.
- Pest mutation testing reported 100 % in parallel mode; that result looks unreliable and must be rechecked per class without `--parallel` (it is slow: run it in the background).
- README (usage, custom PSR-18 client, errors, the 24 h rule, unverified parts), CHANGELOG.
