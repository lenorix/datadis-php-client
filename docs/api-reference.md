# Datadis private API v2 reference

Base URL: `https://datadis.es`. Use HTTPS only.

Sources, strongest first:

1. Real captured payloads and status codes from a production consumer of v1 (the item fields are shared with v2).
2. The official documentation on the Datadis website (the private API page, current; v1, v2, reactive, groups, authorizations, partners, public API parameters and code lists).
3. The official "Manual de usuario API" PDF (June 2023, v1 only), whose sample answers are images: they are the only official samples of every answer, including the public API.
4. A captured request specification (May 2025) and third-party implementations.

Where sources disagree the text says so.

## Authentication

`POST /nikola-auth/tokens/login`, `Content-Type: application/x-www-form-urlencoded`, body `username=<NIF/NIE/CIF>&password=<password>`. Credentials go in the **body**, never the query string (keeps them out of URLs and logs). Some implementations use the query string and it also works.

- Response body: a bare JWT as plain text (VERIFIED). Trim whitespace and surrounding quotes. Reject an empty body, one starting with `<` or `{`, or containing `DOCTYPE` (WAF or maintenance HTML pages have been seen with HTTP 200).
- Failure: 401/403 for bad credentials. One client also treats a 500 on login as bad credentials (REPORTED).
- Token lifetime: **24 hours** (VERIFIED, September 2026: `exp` minus `iat` is 86400). The answer is `text/plain;charset=UTF-8`, an HS512 JWT of about 1100 characters whose claims include `sub`, `authorities` (permissions such as `ROLE_API` or `ROLE_PARTNER`), `publicUser`, `environment`, `iat` and `exp`. The client reads `exp` (base64url payload, signature not verified) minus a two minute skew, and falls back to one hour when `exp` is absent.
- Every data call sends `Authorization: Bearer <jwt>`. On 401 re-login once and repeat the GET once, only for a call that is safe to repeat: a guarded query or a change is not sent again. Never re-login per request.

## Headers required on every data call

| Header | Value | Why |
|--------|-------|-----|
| `Accept` | `application/json` | Without it Datadis answers an empty-body 500 or a 400 "malformed header" (VERIFIED). |
| `Accept-Encoding` | `identity` | Several responses are labelled gzip but are not; curl-based clients fail on them (REPORTED by many). |
| `User-Agent` | an explicit, realistic string | A default library UA has produced 403 (REPORTED). |
| `Authorization` | `Bearer <jwt>` | |

## Endpoints (all GET, parameters in the query string)

Strip `null` parameters and URL-encode every value; a list repeats its key once per item (built by hand, since `http_build_query` would add brackets).

| Endpoint | Required parameters | Optional | Envelope |
|----------|--------------------|----------|----------|
| `/api-private/api/get-supplies-v2` | none | `authorizedNif`, `distributorCode` | `{supplies:[...], distributorError:[...]}` |
| `/api-private/api/get-distributors-with-supplies-v2` | none | `authorizedNif` | `{distExistenceUser:{distributorCodes:[...]}, distributorError:[...]}` |
| `/api-private/api/get-contract-detail-v2` | `cups`, `distributorCode` | `authorizedNif` | `{contract:[...], distributorError:[...]}` |
| `/api-private/api/get-consumption-data-v2` | `cups`, `distributorCode`, `startDate`, `endDate`, `measurementType`, `pointType` | `authorizedNif` | `{timeCurve:[...], distributorError:[...]}` |
| `/api-private/api/get-max-power-v2` | `cups`, `distributorCode`, `startDate`, `endDate` | `authorizedNif` | `{maxPower:[...], distributorError:[...]}` |
| `/api-private/api/get-reactive-data-v2` | `cups`, `distributorCode`, `startDate`, `endDate` | `authorizedNif` | `{reactiveEnergy:{...}, distributorError:[...]}` |
| `/api-private/api/get-groups-v2` | none | none | `[{name, description}]` (one implementation reads a bare list; an envelope is accepted too) |

The v1 equivalents drop the `-v2` suffix and return bare JSON arrays with no `distributorError`. Decoders should tolerate a bare list as well as the envelope.

### Parameter rules

- `startDate` / `endDate`: **`YYYY/MM`** (zero-padded month, slashes), inclusive, whole months only. Day granularity is not supported on the private API (VERIFIED by several implementations; `YYYY/MM/DD` belongs to the public API).
- Window: the last **24 months**. The boundary month (exactly two years back) is refused ("la fecha inicio no puede ser superior a dos años"). Future months are refused ("las fechas deben ser anteriores o iguales al mes actual").
- `measurementType`: `0` hourly, `1` quarter-hourly (SPEC). Quarter-hourly is only offered for point types 1 and 2, and 3 for E-distribución (official web documentation). The API documents `time` only as `hh:mm`, with hourly samples; Datadis's own portal places quarter-hourly readings on the labels `00:15`..`24:00`, which the client takes as the convention when an answer does not tell.
- `pointType` (int 1-5) and `distributorCode` (string, `"1"`-`"8"`) come only from `get-supplies-v2`. Send them as they came.
- `distributorCode` on `get-supplies` is optional: "si se pone este parámetro, se irá directo contra la distribuidora" (official manual).
- A NIF without its final letter is reported to give 401, not 400 (third-party note).
- `authorizedNif`: omit it for the account's own supplies. Send it only when reading a third party's supplies. Sending it for an own supply was rejected and misclassified as an authorization error in production (VERIFIED). Compare NIFs after `trim` and uppercase; send the normalised value.
- `get-contract-detail-v2` takes one CUPS per call.
- No documented maximum range per call. Distributors time out on long ranges, so one month per consumption call is the safe default.
- Latency is high: get-supplies about 3 s, get-contract-detail about 15 s, consumption tens of seconds (measured). Use a 60-180 s data timeout and a short login timeout. Never go below 15 s.

## Response items

### supplies (`get-supplies-v2`)

| Field | Type | Notes |
|-------|------|-------|
| `address` | string | may be `"-"` or empty |
| `cups` | string | |
| `postalCode`, `province`, `provinceCode`, `municipality`, `municipioCode` | string | code fields not always present |
| `distributor` | string | company name, UTF-8 with accents |
| `validDateFrom` | string | `YYYY/MM/DD` or `""` |
| `validDateTo` | string | `YYYY/MM/DD`, **`""` when open-ended** |
| `pointType` | int | 1-5 |
| `distributorCode` | string | JSON string, not int |

The list can be transiently empty or truncated for very large accounts, and a newly registered CUPS takes a while to appear (REPORTED).

### contract detail

The official documentation spells two keys `accesFare` and `installedCapacityKW`; the official sample answer and real payloads use `accessFare` and `installedCapacity` (sent as `1.12E7` in the sample). Both spellings are accepted.

`cups`, `distributor`, `marketer` (may be `"-"` or absent), `tension` (free text, "Baja tensión" or "BAJA TENSION"), `accessFare` (free text, the voltage/power band, **not** a tariff code; a v1 typo `accesFare` exists, accept both), `province`, `municipality`, `postalCode`, `contractedPowerkW` (array of numbers, one per power period: 2 for 2.0TD, 6 for the others), `timeDiscrimination` (`""` or "TARIFA DE TRES/SEIS PERIODOS"), `modePowerControl` ("ICP", "MAXIMETRO", "Maxímetro", `""`), `startDate`, `endDate` (`YYYY/MM/DD`, `""` or null when open), `codeFare` ("2T", "61", "03"), `selfConsumptionTypeCode`, `selfConsumptionTypeDesc`, `section`, `subsection`, `partitionCoefficient`, `cau`, `installedCapacity` (also seen as `installedCapacityKW`; accept both), `dateOwner` (`[{startDate,endDate}]`, **dash dates** `YYYY-MM-DD`), `lastMarketerDate`, `maxPowerInstall` (a **string**, e.g. `"5.5"`). Many fields are nullable.

### consumption (`timeCurve` item)

`cups`, `date` (`YYYY/MM/DD`), `time` (`HH:MM`, see [quirks-and-rules.md](quirks-and-rules.md)), `consumptionKWh` (number, may be null), `obtainMethod` (`"Real"`, `"Estimada"`, `""`; keep as an open string), `surplusEnergyKWh`, `generationEnergyKWh`, `selfConsumptionEnergyKWh` (numbers, absent or null when there is no self-consumption).

### max power (`maxPower` item)

`cups`, `date` (`YYYY/MM/DD`), `time` (`HH:MM`, quarter-hour values such as `09:45`), `maxPower` (number in **kW**, VERIFIED: 3.516 against 3.45 kW contracted; the official documentation says W, which is wrong), one row per period, `period` (string: `"1"`-`"6"` per manual and most fixtures, also `VALLE/LLANO/PUNTA` per manual, `"P1"` in one unverified fixture; normalise defensively).

### reactive (`reactiveEnergy`)

`{cups, energy:[{date, energy_p1..energy_p6}], code, codeDescription}` (VERIFIED key; the official web documentation says `code_desc`, which is read too), `date` documented as `YYYY/MM`; the web documentation's sample has the `energy_p*` values as numbers, one of them negative, and `code` `001` with `Correcto`, a status rather than only an error (the PDF manual has no reactive section). A period without data comes as an object whose every field is null plus a distributor error with code `8` (VERIFIED). An answer with data has not been captured yet (it needs a 3.0TD or larger supply).

### distributors

`{distExistenceUser:{distributorCodes:["2","5",...]}}` in v2, `{"distributorCodes":[...]}` in v1. Codes `"1"`..`"8"`.

### `distributorError` item

`{distributorCode, distributorName, errorCode, errorDescription}` (all strings). Example description: "Error interno distribuidora". This is a **partial failure inside an HTTP 200**.

## Distributor codes (Datadis-specific, unrelated to CNMC's 4-digit codes)

`1` Viesgo, `2` E-distribución, `3` E-redes, `4` ASEME, `5` UFD, `6` EOSA, `7` CIDE, `8` i-DE. Consistent across several implementations and real responses, but treat codes as **opaque strings**: one library had to relax its own validation.

## Functionality outside the v2 endpoints

Implemented because v2 has no equivalent. The authorization and partner endpoints are documented on the official website but their answers are not, so the client reads them tolerantly and keeps them raw.

### Authorizations (private, authenticated, GET)

| Endpoint | Parameters | Answer |
|----------|------------|--------|
| `/api-private/api/new-authorization` | `authorizedNif` (required), `startDate`, `endDate`, `cups` (list, empty = every supply) | undocumented; returned as raw text |
| `/api-private/api/cancel-authorization` | `authorizedNif` (required), `cups` (list) | undocumented; returned as raw text |
| `/api-private/api/list-authorization` | `ownerNif` (optional) | `[{id, ownerDocument, requesterDocument, cups, status, validityDateStart, validityDateEnd, distributorCodeFather}]`, dates as `YYYY-MM-DD HH:MM:SS.f` (VERIFIED) |

Assumptions for the calls that change data (not captured): dates are sent as `YYYY/MM/DD`; a list is sent by repeating the key (`cups=A&cups=B`, the usual binding of array parameters). Both are isolated in one place each so they can be changed once verified. Authorizing the account itself is refused locally.

### Public API (GET)

`/api-public/api-search`, `api-sum-search`, `api-search-auto`, `api-sum-search-auto`.

- Authentication: the login token is required; without it Datadis answers the Spring JSON 401 (VERIFIED). `PublicApiClient` takes the account like `DatadisClient`.
- Required: `startDate`, `endDate` (`YYYY/MM/DD`), `community` (one or two of `01`..`19`); searches (not sums) also require `page` (from 0) and `pageSize` (1-2000). The sums take no paging according to the current documentation, which is what the client sends; UNVERIFIED, since the 2023 manual and one client's specification send `page` and `pageSize` on the sums too, and only `api-search` was captured (see [open-questions.md](open-questions.md)).
- `api-search` / `api-sum-search`: optional `measurementType` (`01`..`05`; the 2023 manual calls it `measurementPointType`, the current documentation `measurementType`, which the client sends, and one client `measurePointType`; UNVERIFIED, and a wrong name would most likely be ignored and give unfiltered totals), `distributor` (CNMC 4 digit codes), `fare`, `provinceMunicipality` (2 or 5 digits), `groupByPostalCode` (integer), `postalCode`, `economicSector` (`1`..`4`), `tension` (`E0`..`E6`), `timeDiscrimination` (`G0`, `E1`, `E2`, `E3`), `sort`.
- `api-search-auto` / `api-sum-search-auto`: `distributor`, `selfConsumption` (modality codes), `province` (2 digits); `sort` only on the search.
- Several values in one parameter are comma-separated. `sort` takes field names, `-` for descending.
- Answers (official samples): searches are lists of rows with `dataDay`, `dataMonth`, `dataYear` (integers), the filter fields (`community` as a name, empty strings when not grouped), `sumEnergy`, `sumContracts` (and `sumPower`, `selfConsumption` for self-consumption), and hourly totals `mi1`..`mi25`, all numbers as **strings**. Sums are `[{sumEnergy, sumContract}]` (singular) and `sumPower` for self-consumption, as JSON numbers.

### Partner programme (GET, authenticated)

`partner-user-list` and `partner-agreement-date` are VERIFIED (see [quirks-and-rules.md](quirks-and-rules.md)): the client returns `PartnerUser` results and the agreement date as text or null. `partner-delete-user` changes data and has not been captured; its answer is returned raw.

| Endpoint | Parameters | Meaning |
|----------|------------|---------|
| `/api-private/api/partner-user-list` | none | users linked to the partner |
| `/api-private/api/partner-delete-user` | `nif` (required) | unlinks a user; changes data, never retried |
| `/api-private/api/partner-agreement-date` | `nif` (optional, for callers allowed to consult another partner) | start date of the partner agreement |

