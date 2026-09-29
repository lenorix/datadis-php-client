# Quirks and rules learned from real use

## The 24 hour repetition rule (critical)

Datadis refuses an identical query made within 24 hours with **HTTP 429** ("Consulta ya realizada en las últimas 24 horas").

- The key is the user plus the query parameters. One implementation reports that the key **ignores the endpoint**, so max-power and reactive with the same window collide; the manual lists `cups, distributorCode, startDate, endDate, measurementType, pointType, authorizedNif`. Assume the stricter reading.
- It counts calls **made**, not calls that succeeded. A response caused by a user error (bad parameters, missing authorization) also burns the tuple.
- It applies to consumption, max power and reactive. It does not apply to supplies, contract detail or distributors (VERIFIED for supplies/contract in practice).
- Two ranges inside the same month are the same query, because the wire only carries `YYYY/MM`.
- `authorizedNif` omitted and `authorizedNif` present are different queries.
- **Never retry a 429.** Retrying wastes time and changes nothing.
- PSR-18 cannot distinguish "never sent" from "sent but no answer": a read timeout is reported as a network exception (Guzzle: curl error 28 as `ConnectException`). Short timeouts caused curl 28 on every call in production, so this is common. **A network exception on a guarded endpoint means the outcome is unknown: treat it as possibly sent, do not retry, keep the tuple.**
- The only provably unsent failures are pre-flight ones: configuration or parameter validation, and a login failure before the data request.
- A 5xx or a timeout **after** the request was sent may have consumed the tuple. Do not blindly retry guarded endpoints.
- Cache even empty results and 429 outcomes for 24 h on the consumer side. The package offers a fingerprint builder and an optional PSR-16 guard, never mandatory.
- Whether a 429 is what an actual repeat returns has been reported by several sources but never captured by the reference consumer. Treat the mapping as SPEC/REPORTED.

## Status codes as observed

| Status | Meaning |
|--------|---------|
| 200 + non-empty list | Success. |
| 200 + empty list | Normal "no data" (month not published, out of retention, new contract's first month). **Never** treat as zero consumption. |
| 200 + empty list + non-empty `distributorError` | The distributor failed. Not "no data". |
| 200 + non-JSON body | Failure (HTML maintenance page). Do not retry blindly. |
| 200 + empty body, or 204 | No data. |
| 400 | Rejected parameters (malformed CUPS or `distributorCode`, `startDate` before the supply's `validDateFrom`, boundary month, missing `Accept`). Permanent: never resend the identical call. Body is `text/plain`, not JSON. |
| 401 | Token missing or expired. Re-login once. |
| 403 | The `authorizedNif` has no valid authorization for that CUPS, or a stale `distributorCode`/`pointType`. Also seen for blocked User-Agents. |
| 404 | Older/v1 behaviour for "no data" ("Data not found"). Map to a no-data condition, not a fatal error. A wrong path also gives a Spring-style JSON 404. |
| 429 | Repetition window. Never retry. |
| 500/502/503/504 | Datadis or distributor failure. Empty-body 5xx happens for some CUPS. Retry only on unguarded endpoints. |

Error bodies come as `text/plain`, Spring JSON `{"timestamp","status","error","message","path"}`, or `{"message":"..."}`. Read defensively. Datadis **echoes rejected parameters (CUPS, NIF) in error bodies**, so any excerpt kept in an exception or log must be redacted by shape.

Third-party consumption can return 200 with an empty `timeCurve` even when supplies and contract work for the same authorized NIF (one support report, unresolved).

## Hour labels

`time` on hourly data runs **`01:00` to `24:00` and marks the END of the interval** (VERIFIED). `01:00` is the hour 00:00-01:00, `24:00` is 23:00-24:00.

- Hour index (0-23) = `H - 1` for `H` in 1..24. Anything else (`00:00`, `25:00`, `1:5`) has no valid index.
- Never parse `24:00` with a normal time parser. `24:00` of day D is the instant `D+1 00:00`. Two open-source clients map it to `00:00` of the **same** date, which is wrong by 24 hours.
- An i-DE glitch adds an extra `00:00` row on a day that already has 24 rows (REPORTED). Drop or flag it.
- One source reports trailing zero rows for unpublished hours in the current month. Defend against it; do not assume it.

## Daylight saving time (VERIFIED with real captures)

Dates and hours are local Spanish civil time with no offset.

- Autumn change (25 hours, e.g. 2025-10-26): 25 rows, labels `01:00`..`24:00` with **`03:00` twice**, both `Real`, different values. No `25:00`, no summer/winter marker.
- Spring change (23 hours, e.g. 2026-03-29): 23 rows with **`03:00` missing**.
- Consequences: never key readings by `(date, hour)`, never assert 24 rows per day, never add a unique constraint on that pair. Keep source order so duplicates stay distinguishable. Sum both duplicates for energy totals.
- Zone: Peninsula, Baleares, Ceuta and Melilla use Europe/Madrid. Canarias uses Atlantic/Canary (Datadis' semantics there are unverified). Compute with an explicit zone, never the host default.

## Transport

- Mislabelled gzip: send `Accept-Encoding: identity`. With Guzzle, mislabelled gzip makes curl fail before the body is seen.
- Use HTTPS with TLS verification on. Never disable verification by default.
- Some hosting (Cloudflare Workers) got 530; IPv6-first DNS failed in serverless Node. Not our concern, but explains odd reports.
- Build query strings with proper URL encoding.

## Data quirks

- `consumptionKWh` may be `null`. Discard those rows individually and expose how many were dropped. If every row of a non-empty response is unusable, the response is not interpretable.
- `obtainMethod` may be `""`. Keep it an open string.
- `validDateTo` and `endDate` are `""` when open-ended.
- `distributorCode` is a string, `pointType` is an int.
- Real numbers arrive as JSON floats. Converting floats to strings can yield exponent notation (`1.0E-5`); test decimal conversion. Use `JSON_PRESERVE_ZERO_FRACTION` when re-encoding raw rows.
- CUPS: 20 characters (`ES` + 16 digits + 2 letters), optionally followed by a 2-character frontier suffix (a digit and a letter, e.g. `0F`). Retailers sometimes print extra characters on invoices; the value shown in the Datadis portal is the reference. Match supplies on the first 20 characters, uppercased and trimmed. There is no documented check-letter algorithm; do not claim one.
- Multiple supply rows for the same CUPS (successive contracts, distributor change): prefer the one with an empty `validDateTo`, otherwise the greatest `validDateFrom` (compare parsed dates, not strings).
- Data lag: a month may be incomplete until several days after it ends, and distributors sometimes publish weeks late. Re-asking the last two months is common (mind the 24 h rule: use a different window, not the same query).
- Keep UTF-8 intact. Do not strip accents or "repair" text.

## Personal data

CUPS, NIF and consumption curves are personal data (curves reveal occupancy habits). Never put raw error bodies, CUPS or NIF in exception messages or logs. Redact by **shape** (CUPS, NIF, NIE, CIF patterns), not by comparing with the values sent, because Datadis may echo another supply's identifiers.
