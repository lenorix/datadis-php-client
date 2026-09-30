# Testing strategy

## Tools

- **Pest** for every test (`vendor/bin/pest`). Pest 4 runs on PHPUnit 12.
- **Eris** (`giorgiosironi/eris`) for property-based testing (PBT). Smoke-tested with Pest 4 / PHPUnit 12.5 and Eris 1.1: `uses(Eris\TestTrait::class)` in the test file, then `$this->forAll(...)->then(...)`. A failing property fails the test and prints a seed.
  - PHPUnit 12 dropped docblock metadata, so `@eris-repeat` style annotations do not work. Control iterations with `->limitTo(n)` through a helper in `tests/Pest.php` that reads **our own** env var (`ERIS_ITERATIONS` is not an Eris feature; only `ERIS_SEED` and `ERIS_ORIGINAL_INPUT` are).
  - Eris prints a reproduce hint using `vendor/bin/phpunit`. Reproduce with `ERIS_SEED=<seed> vendor/bin/pest --filter '<test name>'` instead.
  - Running the suite: bare `vendor/bin/pest` printed only a coverage warning and ran nothing while `phpunit.xml.dist` had a `<coverage><report>` block. Always check that the summary line shows a test count. Use `--no-coverage` for normal runs until the config is fixed in M0. In zsh use `$pipestatus[1]` (not `${PIPESTATUS[0]}`) to read an exit code through a pipe.
- No live API calls in tests, ever. A recording PSR-18 test double serves canned responses and records requests.

## TDD loop

Red (write a failing test that states the behaviour), green (smallest code that passes), refactor. One behaviour per test. Tests describe the contract, not the implementation. Commit only when the whole suite passes.

## What a test must be

- A realistic check of behaviour someone relies on, not a second copy of the code. Prefer one test through the public client, with an answer shaped like Datadis's, over several that restate internals.
- No filler for coverage: no "is an instance of", no asserting a constant, no status codes or dates Datadis never produces, no tests of PHP itself or of a fixture.
- Answers replay what Datadis really sends: JSON labelled `text/plain` for data, plain text labelled `application/json` for refusals, the Spring JSON for a refused token, an empty 500 for a missing parameter. A case that rests on an assumption (for example a v2 error captured on v1) says so in its name.
- A failure test also checks how many requests were made and whether the request counts as sent, so a hidden retry or re-login cannot pass. The fake HTTP client records requests nothing was queued for, and a global hook fails the test.
- A property needs an oracle of its own (hand-written arithmetic, hard-coded windows), generators that reach the interesting cases, and assertions that could fail. A small fixed domain is a dataset, not a random sample.

## Property-based testing rules

- Every pure helper gets properties: month round-trip and arithmetic, chunk coverage without overlap, hour label bijection over 1..24 and rejection otherwise, redactor totality and idempotence, CUPS normalisation idempotence, tariff-shape parser totality and normalisation-invariance, fingerprint determinism and sensitivity to every parameter, decimal conversion (including exponent notation).
- Decoders get generative tests: random valid payloads decode and re-encode without loss of `raw`; random malformed payloads only ever raise `DatadisException` subclasses.
- Eris pitfalls (learned in another project): register a stubbed HTTP double **once** outside the property loop, with a closure that reads a variable by reference. First-registered stub wins, so re-registering per iteration keeps serving the first response. Give each field the code distinguishes a different value so mixed-up fields are detected.
- Seed failures: when a property fails, add the shrunk counterexample as a plain example test.
- Review phase: after the TDD implementation, run mutation-style manual checks (break the code on purpose, confirm a test fails) on the invariants that matter most: no retry on 429, `authorizedNif` omission, hour mapping, redaction.

## Fixtures and provenance

Every fixture file lives under `tests/Fixtures/` and its provenance is recorded in `tests/Fixtures/README.md` as one of VERIFIED (real capture, anonymised), SPEC (from the captured specification), or SYNTHETIC. A previous project passed its whole suite on hand-made fixtures that did not match the real API, so **synthetic fixtures must be marked and the behaviours they cover listed as unverified**.

Fictitious safe identifiers:

- CUPS: `ES0031300000000001JN0F` (22 chars), `ES0031300000000001JN` (20 chars)
- NIF: `12345678Z`, account and third party: `12345678Z` / `87654321X`
- Credentials: `test-user` / `test-password`
- Base URL in tests: `https://datadis.test`

Never copy real payloads with real CUPS, NIF, addresses or postal codes of real people.

Minimum fixture set per endpoint: normal success, empty list, `distributorError` only, `distributorError` plus data, null-heavy row, and the real error answers listed in [quirks-and-rules.md](quirks-and-rules.md) (400 and 404 as plain text labelled JSON, 401 Spring JSON, 500 with an empty body). Hourly consumption additionally: 24-hour day, 25-hour day (`03:00` twice), 23-hour day (`03:00` missing), the `00:00` glitch row, `24:00` at month and year end, `consumptionKWh: null`, `obtainMethod: ""`.

Additional scenario tests required: mislabelled gzip body, HTTP 200 HTML page, numeric strings, hostile `Retry-After`, token expiry with a fake clock, one re-login on 401 and no more, login rejection not consuming the guard, `authorizedNif` equal to the account being omitted, request headers (`Accept`, `Accept-Encoding`, `User-Agent`, `Authorization`), and that neither password nor token appears in any exception message.

## Architecture tests

`tests/ArchTest.php` enforces: no `dd`/`dump`/`ray` in `src`, strict types everywhere, final classes (except the exception base), every exception extends `DatadisException`, Guzzle used only by the default wiring (`GuzzleClientFactory`, `DatadisClient`, `PublicApi`), and immutable results and values. Forbidden project names are checked with `grep` before each commit, never by a test, since a test would have to spell them out.

## Quality gates before every commit

`composer test` green, `composer test-coverage` at 98 % or more, `composer phpstan` clean at level max, `vendor/bin/pint` clean, no real personal data (`grep` for `ES\d{16}` patterns that are not the fictitious ones).
