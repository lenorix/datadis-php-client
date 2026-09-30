# Fixtures

Every fixture states where it comes from. Use only these levels:

- **VERIFIED**: real capture, anonymised.
- **SPEC**: derived from the captured API specification or the official manual.
- **SYNTHETIC**: hand-made. Behaviour covered only by synthetic fixtures is unverified against the live API.

Identifiers are fictitious: CUPS `ES0031300000000001JN0F` (22 chars) and `ES0031300000000001JN` (20 chars), NIF `12345678Z`, third party `87654321X`. Never add real personal data.

| File | Level | Notes |
|------|-------|-------|
| `v2/supplies.json` | SPEC | Envelope from the captured spec; the row fields are those of the real v1 rows (`provinceCode`, `municipioCode` included). Values invented. Two rows: open-ended and closed contract. |
| `v2/contract-detail.json` | SPEC | Field names from spec and manual, including `installedCapacityKW` and a dash-dated `dateOwner`. Values invented. |
| `v2/consumption.json` | SPEC | Includes the `24:00` label, an empty `obtainMethod`, a null and a missing `consumptionKWh`. |
| `v2/reactive.json` | SYNTHETIC | No real success body exists for reactive data. Field names from the manual. |
| `v2/distributor-error-only.json` | SPEC | Partial failure inside an HTTP 200. |
| DST day payloads | SYNTHETIC values, VERIFIED shape | Built in `tests/Support/Payloads.php`: 25 rows with `03:00` twice, 23 rows without `03:00`. |
| `v1/list-authorization.json` | SPEC | Field names from the manual only; no real capture exists. Mixed id and date types on purpose. |
| `public/search.json`, `public/search-auto.json` | SPEC | Copied from the sample answers of the official API manual (aggregated open data, no personal data). Numbers arrive as strings; the date is `dataDay`/`dataMonth`/`dataYear`. |
| `public/sum-search.json`, `public/sum-search-auto.json` | SPEC | Sample answers of the official manual: note `sumContract` in singular and numbers as JSON numbers. |
| `v2/groups.json` | SYNTHETIC | The official documentation lists only `name` and `description`; one implementation reads a bare list. |
| `v1/contract-detail-blank.json` | VERIFIED | Real answer (September 2026) of `get-contract-detail` for a CUPS the account cannot see: HTTP 200 with one row whose fields are all empty. Contains no personal data. |
| `errors/401-spring.json` | VERIFIED | Real 401 for a missing or altered token. |
| Error bodies inlined in `tests/Feature/RealErrorAnswersTest.php` | VERIFIED | Real status, content type and body of each case (September 2026). |
| `v1/supplies-authorized.json`, `v1/contract-detail-authorized.json`, `v1/max-power-authorized.json` | VERIFIED shape | Real answers (September 2026) for a supply read with `authorizedNif`, with the identifiers, address and postal code replaced by fictitious ones. Structure, types, empty strings and nulls are exactly as received; the maximum power values are kept because they are what proves the unit is kW. |
| Real-shaped consumption months in `tests/Feature/RealDataAnswersTest.php` | VERIFIED shape, SYNTHETIC values | Built by `Payloads::realMonth()`: the row fields (self-consumption fields as `null`) and row counts of real answers; the consumption values are invented. |
