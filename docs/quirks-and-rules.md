# Quirks and rules learned from real use

## The 24 hour repetition rule (critical)

Datadis refuses an identical query made within 24 hours with **HTTP 429** ("Consulta ya realizada en las últimas 24 horas").

- The key is the user plus the query parameters. The official manual lists `cups, distributorCode, startDate, endDate, measurementType, pointType, authorizedNif` for consumption but only `cups, distributorCode, startDate, endDate` for maximum power (no `authorizedNif`). One implementation reports that the key **ignores the endpoint**, so max power and reactive with the same window collide. The guard takes the stricter reading of both: endpoint ignored, and `authorizedNif` ignored for maximum power and reactive.
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

## Real answers (VERIFIED, September 2026, v1 paths)

| Case | Status | Content type | Body |
|------|--------|--------------|------|
| Login | 200 | `text/plain;charset=UTF-8` | the JWT |
| Month without data (consumption, max power, also a 3 month range and the current month) | 200 | `text/plain` | `[]` |
| Quarter-hourly (`measurementType=1`) with point type 5 | 200 | `text/plain` | `[]`: not refused |
| Contract detail of a CUPS the account cannot see (or that does not exist) | 200 | `text/plain` | one row whose fields are all `""`, `null` or `[]` |
| Supplies of an account without supplies (also `authorizedNif=` empty) | 404 | `application/json` | `No supplies` (plain text despite the type) |
| Supplies with an `authorizedNif` that authorized nothing | 403 | `application/json` | `No authorized supplies` |
| Contract detail or consumption with an `authorizedNif` without consent for that CUPS | **400** | `application/json` | `No se encuentra autorizado el cups introducido` |
| No token, garbage token, altered signature | 401 | `application/json` | Spring JSON `{"timestamp","status","error","message":"No message available","path"}` |
| No `Accept` header | 400 | `text/plain;charset=UTF-8` | `Parámetro en cabecera requerido en estado vacío, con formato erróneo o con valores fuera de rango` |
| Unknown `distributorCode` (`99`) | 400 | `application/json` | `Parámetro requerido en estado vacío, con formato erróneo, o con valores fuera de rango / Parámetro de ordenación erróneo` |
| Dates in `YYYY-MM`, in the future, older than two years, or start after end | 400 | `application/json` | `Fechas incorrectas revise: Formato de fechas YYYY/MM, las fechas deben ser anteriores o iguales al mes actual, fecha inicio no superior a fecha fin. La fecha inicio no puede ser superior a dos años.` |
| `pointType=9` | 400 | `application/json` | `PointType incorrecto ` |
| A required parameter missing (contract without `distributorCode`, consumption without `measurementType`, max power without dates) | **500** | | empty |
| Unknown path | **403** | `text/plain` | `403 Forbidden` |

| Supply read with `authorizedNif` of its holder | 200 | `text/plain` | the supply row with `provinceCode` and `municipioCode` |
| Contract seen by a third party | 200 | `text/plain` | `marketer` is `"-"`, `lastMarketerDate` is `""` (not null), `maxPowerInstall` a string |
| Consumption of a whole month | 200 | `text/plain` | one row per real hour (744 in July; 1463 for March and April, the 23 hour day included); self-consumption fields present and `null` |
| Consumption of the current month | 200 | `text/plain` | data up to about two days before the call |
| Maximum power of a 2.0TD month | 200 | `text/plain` | one row per period (`"1"`, `"2"`, `"3"`), in kW (3.516 with 3.45 kW contracted); one row at `00:00` in period 2 |
| `authorizedNif` equal to the account's own NIF | 403 (supplies) / 400 (data) | `application/json` | `No authorized supplies` / `No se encuentra autorizado el cups introducido` |
| CUPS in lowercase, or its 20 character form, or a CUPS the holder did not authorize | 400 | `application/json` | `No se encuentra autorizado el cups introducido` |
| An existing but wrong `distributorCode` (`1` instead of `2`) | 200 | `text/plain` | `[]`: no error |
| An unknown `distributorCode` (`99`) on consumption | 400 | `application/json` | `CUPS o distributor no válido ` |
| `pointType` different from the supply's (`3` instead of `5`) | 200 | `text/plain` | the data: not checked |
| `measurementType=7` | 400 | `application/json` | `MeasurementType incorrecto ` |
| `startDate` with a day (`YYYY/MM/DD`) | 400 | `application/json` | the dates message |
| Maximum power for a month before the window | 400 | `application/json` | the generic `Parámetro requerido en estado vacío...` message, not the dates one |

Consequences in the client: JSON is read whatever the content type; the blank contract row is dropped (an empty result, not a failure); "No supplies" is an empty supplies list; the "no se encuentra autorizado" 400 is an `AuthorizationException`; a 500 is never retried because it can be a client mistake; every required parameter is always sent. Latency in this capture was about one second per call.

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
| 403 | The `authorizedNif` has no authorized supplies, or a stale `distributorCode`/`pointType`. Also seen for blocked User-Agents and for unknown paths. Contract detail and consumption report a missing consent with a 400 instead. |
| 404 | "No data": `No supplies` for an account without supplies (verified), "Data not found" reported for data calls. Map to a no-data condition, not a fatal error. |
| 429 | Repetition window. Never retry. |
| 500/502/503/504 | Datadis or distributor failure. An empty 500 is also the answer to a missing required parameter (verified), so a 500 is never retried. |

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
- How the client places them: a label is the wall clock at the END of the interval read with the offset in effect during that interval, so the first `03:00` of the autumn day ends at 03:00 CEST (01:00 UTC) and the second at 03:00 CET (02:00 UTC). The n-th row with the same date and time is its n-th occurrence. A label in the skipped spring hour, or a third repetition, gets no interval and is flagged. A max power instant in the repeated hour is read as its first occurrence.
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
- CUPS: 20 characters (`ES` + 16 digits + 2 letters), optionally followed by a 2-character frontier suffix (a digit and a letter, e.g. `0F`). Retailers sometimes print extra characters on invoices. Match supplies on the first 20 characters, uppercased and trimmed, but **send the CUPS exactly as `get-supplies` returns it**: the 20 character form and lowercase are refused as not authorized (verified). There is no documented check-letter algorithm; do not claim one.
- Multiple supply rows for the same CUPS (successive contracts, distributor change): prefer the one with an empty `validDateTo`, otherwise the greatest `validDateFrom` (compare parsed dates, not strings).
- Data lag: the current month has data up to about two days before (verified); a month may be incomplete until several days after it ends, and distributors sometimes publish weeks late. Re-asking the last two months is common (mind the 24 h rule: use a different window, not the same query).
- Keep UTF-8 intact. Do not strip accents or "repair" text.

## Personal data

CUPS, NIF and consumption curves are personal data (curves reveal occupancy habits). Never put raw error bodies, CUPS or NIF in exception messages or logs. Redact by **shape** (CUPS, NIF, NIE, CIF patterns), not by comparing with the values sent, because Datadis may echo another supply's identifiers.

## Easy to misread

- An empty consumption or contract answer can mean a wrong `distributorCode` that happens to exist: Datadis answers `[]`, not an error (verified). Take the code from `get-supplies`.
- `pointType` is not checked against the supply (verified), so a wrong one does not show.
- Maximum power times look like the END of a quarter, like consumption labels: a real row at `00:00` in period 2 (llano) only fits the quarter 23:45-24:00 of the previous day, since 00:00-00:15 is valley. The instant is the same either way; mind it when attributing a maximum to a period.
