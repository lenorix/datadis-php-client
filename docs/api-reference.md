# Datadis private API v2 reference

Base URL: `https://datadis.es`. Use HTTPS only.

Sources: a captured request specification (May 2025, best evidence for parameters), the official manual, and real captured payloads from a production consumer (mostly v1-shaped but the item fields are shared). Where sources disagree the table says so.

## Authentication

`POST /nikola-auth/tokens/login`, `Content-Type: application/x-www-form-urlencoded`, body `username=<NIF/NIE/CIF>&password=<password>`. Credentials go in the **body**, never the query string (keeps them out of URLs and logs). Some implementations use the query string and it also works.

- Response body: a bare JWT as plain text (VERIFIED). Trim whitespace and surrounding quotes. Reject an empty body, one starting with `<` or `{`, or containing `DOCTYPE` (WAF or maintenance HTML pages have been seen with HTTP 200).
- Failure: 401/403 for bad credentials. One client also treats a 500 on login as bad credentials (REPORTED).
- Token lifetime: **unknown**. Read the JWT `exp` claim (base64url payload, do not verify the signature) and subtract a safety skew (about 2 minutes). Fall back to a conservative TTL (1 hour) when `exp` is absent.
- Every data call sends `Authorization: Bearer <jwt>`. On 401 re-login once and retry the GET once. Never re-login per request.

## Headers required on every data call

| Header | Value | Why |
|--------|-------|-----|
| `Accept` | `application/json` | Without it Datadis answers an empty-body 500 or a 400 "malformed header" (VERIFIED). |
| `Accept-Encoding` | `identity` | Several responses are labelled gzip but are not; curl-based clients fail on them (REPORTED by many). |
| `User-Agent` | an explicit, realistic string | A default library UA has produced 403 (REPORTED). |
| `Authorization` | `Bearer <jwt>` | |

## Endpoints (all GET, parameters in the query string)

Strip `null` parameters and use `http_build_query` so values are URL-encoded.

| Endpoint | Required parameters | Optional | Envelope |
|----------|--------------------|----------|----------|
| `/api-private/api/get-supplies-v2` | none | `authorizedNif`, `distributorCode` | `{supplies:[...], distributorError:[...]}` |
| `/api-private/api/get-distributors-with-supplies-v2` | none | `authorizedNif` | `{distExistenceUser:{distributorCodes:[...]}, distributorError:[...]}` |
| `/api-private/api/get-contract-detail-v2` | `cups`, `distributorCode` | `authorizedNif` | `{contract:[...], distributorError:[...]}` |
| `/api-private/api/get-consumption-data-v2` | `cups`, `distributorCode`, `startDate`, `endDate`, `measurementType`, `pointType` | `authorizedNif` | `{timeCurve:[...], distributorError:[...]}` |
| `/api-private/api/get-max-power-v2` | `cups`, `distributorCode`, `startDate`, `endDate` | `authorizedNif` | `{maxPower:[...], distributorError:[...]}` |
| `/api-private/api/get-reactive-data-v2` | `cups`, `distributorCode`, `startDate`, `endDate` | `authorizedNif` | `{reactiveEnergy:{...}, distributorError:[...]}` |

The v1 equivalents drop the `-v2` suffix and return bare JSON arrays with no `distributorError`. Decoders should tolerate a bare list as well as the envelope.

### Parameter rules

- `startDate` / `endDate`: **`YYYY/MM`** (zero-padded month, slashes), inclusive, whole months only. Day granularity is not supported on the private API (VERIFIED by several implementations; `YYYY/MM/DD` belongs to the public API).
- Window: the last **24 months**. The boundary month (exactly two years back) is refused ("la fecha inicio no puede ser superior a dos años"). Future months are refused ("las fechas deben ser anteriores o iguales al mes actual").
- `measurementType`: `0` hourly, `1` quarter-hourly (SPEC). Quarter-hourly is only offered for point types 1 and 2 (and 3 for one distributor). The quarter-hour `time` format is undocumented anywhere.
- `pointType` (int 1-5) and `distributorCode` (string, `"1"`-`"8"`) come only from `get-supplies-v2`. Send them as they came.
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

`cups`, `distributor`, `marketer` (may be `"-"` or absent), `tension` (free text, "Baja tensión" or "BAJA TENSION"), `accessFare` (free text, the voltage/power band, **not** a tariff code; a v1 typo `accesFare` exists, accept both), `province`, `municipality`, `postalCode`, `contractedPowerkW` (array of numbers, one per power period: 2 for 2.0TD, 6 for the others), `timeDiscrimination` (`""` or "TARIFA DE TRES/SEIS PERIODOS"), `modePowerControl` ("ICP", "MAXIMETRO", "Maxímetro", `""`), `startDate`, `endDate` (`YYYY/MM/DD`, `""` or null when open), `codeFare` ("2T", "61", "03"), `selfConsumptionTypeCode`, `selfConsumptionTypeDesc`, `section`, `subsection`, `partitionCoefficient`, `cau`, `installedCapacity` (also seen as `installedCapacityKW`; accept both), `dateOwner` (`[{startDate,endDate}]`, **dash dates** `YYYY-MM-DD`), `lastMarketerDate`, `maxPowerInstall` (a **string**, e.g. `"5.5"`). Many fields are nullable.

### consumption (`timeCurve` item)

`cups`, `date` (`YYYY/MM/DD`), `time` (`HH:MM`, see [quirks-and-rules.md](quirks-and-rules.md)), `consumptionKWh` (number, may be null), `obtainMethod` (`"Real"`, `"Estimada"`, `""`; keep as an open string), `surplusEnergyKWh`, `generationEnergyKWh`, `selfConsumptionEnergyKWh` (numbers, absent or null when there is no self-consumption).

### max power (`maxPower` item)

`cups`, `date` (`YYYY/MM/DD`), `time` (`HH:MM`, quarter-hour values such as `09:45`), `maxPower` (number, **kW** in practice; one implementation documents W, contradicted by every fixture), `period` (string: `"1"`-`"6"` per manual and most fixtures, also `VALLE/LLANO/PUNTA` per manual, `"P1"` in one unverified fixture; normalise defensively).

### reactive (`reactiveEnergy`)

`{cups, energy:[{date, energy_p1..energy_p6}], code, code_desc}`, `date` documented as `YYYY/MM`. Least verified response: only synthetic fixtures exist. `reactiveEnergy` may be missing or `{}`. Usually empty for domestic 2.0TD supplies.

### distributors

`{distExistenceUser:{distributorCodes:["2","5",...]}}` in v2, `{"distributorCodes":[...]}` in v1. Codes `"1"`..`"8"`.

### `distributorError` item

`{distributorCode, distributorName, errorCode, errorDescription}` (all strings). Example description: "Error interno distribuidora". This is a **partial failure inside an HTTP 200**.

## Distributor codes (Datadis-specific, unrelated to CNMC's 4-digit codes)

`1` Viesgo, `2` E-distribución, `3` E-redes, `4` ASEME, `5` UFD, `6` EOSA, `7` CIDE, `8` i-DE. Consistent across several implementations and real responses, but treat codes as **opaque strings**: one library had to relax its own validation.

## v1-only functionality (UNVERIFIED)

Implemented because v2 has no equivalent. Everything below comes from the manual and a captured request specification; **no real answer has been captured**, so the client reads answers tolerantly and keeps them raw.

### Authorizations (private, authenticated, GET)

| Endpoint | Parameters | Answer |
|----------|------------|--------|
| `/api-private/api/new-authorization` | `authorizedNif` (required), `startDate`, `endDate`, `cups` (list, empty = every supply) | undocumented; returned as raw text |
| `/api-private/api/cancel-authorization` | `authorizedNif` (required), `cups` (list) | undocumented; returned as raw text |
| `/api-private/api/list-authorization` | `ownerNif` (optional) | `[{id, ownerDocument, requesterDocument, status, validityDateStart, validityDateEnd, distributorCodeFather}]` |

Assumptions: dates are sent as `YYYY/MM/DD`; a list is sent by repeating the key (`cups=A&cups=B`, the usual binding of array parameters). Both are isolated in one place each so they can be changed once verified. Authorizing the account itself is refused locally.

### Public API (no authentication, GET)

`/api-public/api-search`, `api-sum-search`, `api-search-auto`, `api-sum-search-auto`.

- Common: `startDate`, `endDate` (`YYYY/MM/DD`, required), `page` (from 0, required), `pageSize` (1-2000, required), `community` (required, one or two of `01`..`19`), `distributor` (CNMC 4 digit codes), `sort` (`dataDate`, `community`, `province`, `municipality`, `postalCode`, `fare`, `measurePointType`, `tension`, `economicSector`, `timeDiscrimination`, `distributor`, `sumEnergy`, `sumContracts`; `-` prefix for descending).
- `api-search` / `api-sum-search`: `measurementType` (required, `01`..`05`), `fare`, `provinceMunicipality` (2 or 5 digits), `postalCode`, `economicSector` (`1`..`4`), `tension` (`E0`..`E6`), `timeDiscrimination` (`G0`, `E1`, `E2`, `E3`).
- `api-search-auto` / `api-sum-search-auto`: `selfConsumption` (modality codes 31-33, 41-43, 51-58, 61-64, 71-74, 77), `province` (2 digits).
- Several values in one parameter are comma-separated.
- Answer: unknown. Rows are expected to carry the sort fields plus `sumEnergy`, `sumContracts` and hourly totals `mi1`..`mi25` (the 25th for the extra autumn hour). The client accepts a bare list, a list under `content`/`data`/`results`/`items`, or a single object.
