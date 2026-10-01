# Spanish electricity domain notes

Only what a Datadis client needs to interpret data. Regulatory sources: Circular CNMC 3/2020 (access tariffs and periods), RD 1110/2007 (metering points), RD 244/2019 (self-consumption).

## Access tariffs

| Tariff | Voltage / power | Energy periods | Power periods |
|--------|-----------------|----------------|---------------|
| 2.0TD | low voltage, up to 15 kW | 3 (P1-P3) | 2 |
| 3.0TD | low voltage, above 15 kW | 6 | 6 |
| 6.1TD | 1 kV up to 30 kV (lower bound inclusive) | 6 | 6 |
| 6.2TD | 30 kV up to 72.5 kV | 6 | 6 |
| 6.3TD | 72.5 kV up to 145 kV | 6 | 6 |
| 6.4TD | 145 kV and above | 6 | 6 |

- `accessFare` in contract detail is free text describing the band, not a code. Real strings seen: `BAJA TENSION y POTENCIA <= 15 kW`, `2.0TD PEAJE ATR`, `BAJA TENSION Y POTENCIA  > 15 kW` (double space). Exact-string matching failed twice in production. Parse by **shape**: replace `≤` with `<=` and `≥` with `>=` first (ASCII transliteration drops them and loses the only signal that separates 2.0TD from 3.0TD), normalise (ASCII, lowercase, collapse whitespace), band by low-voltage 15 kW threshold or by kV lower bound (decimal comma or dot), cross-check with an alias like `2.0TD`, and return null when band and alias disagree. Never guess.
- Cross-check with `contractedPowerkW`: 2 values for 2.0TD, 6 for every other tariff. If they disagree, keep the contract and mark the tariff unresolved instead of throwing.
- Contracted power in 6-period tariffs is non-decreasing (P1 <= ... <= P6). 2.0TD has no ordering rule. Real payloads have violated it, so the client must not enforce it.
- `codeFare` is the CNMC code (`2T`...), separate from `accessFare`.

## Periods (informational; consumers decide whether to use them)

- 2.0TD hours (Peninsula, Baleares, Canarias): P1 punta 10-13 and 18-21, P2 llano 8-9, 14-17, 22-23, P3 valle 0-7. Ceuta and Melilla shift one hour later. Weekends and national holidays are all P3.
- 3.0TD and 6.1TD to 6.4TD share one six-period calendar (Circular 3/2020, art. 7.2, unchanged by its amendments), shipped as `SixPeriodSchedule`. Seasons by month per territory (Peninsula: high Jan, Feb, Jul, Dec; medium-high Mar, Nov; medium Jun, Aug, Sep; low Apr, May, Oct; the islands, Ceuta and Melilla have their own). On working days 00-08 is P6, the "high" hours are 9-14 and 18-22 on the Peninsula, 10-15 and 18-22 in the Balearic and Canary Islands, 10-15 and 19-23 in Ceuta and Melilla, and the rest "medium". High/medium give P1/P2, P2/P3, P3/P4 and P4/P5 by season, except in the Canary Islands (P1/P3, P2/P3, P2/P4, P4/P5) and Ceuta (P1/P4, P2/P3, P2/P4, P3/P5). Weekends, 6 January and national holidays are P6.
- National fixed holidays for period purposes: 1 Jan, 6 Jan, 1 May, 15 Aug, 12 Oct, 1 Nov, 6 Dec, 8 Dec, 25 Dec. **Good Friday is excluded** (movable and substitutable holidays are not counted). Regional and local holidays are out of scope.

## Metering points (`pointType`)

Integer 1-5 (RD 1110/2007). 1-3 have quarter-hourly metering and a maximeter (large consumers). 4-5 are smaller supplies, most domestic ones are 5. Do not attach role names to the numbers. Quarter-hourly `measurementType` is only offered for 1 and 2 (and 3 for one distributor).

## Territories and time

Peninsula, Baleares, Ceuta, Melilla: CET/CEST. Canarias: WET/WEST. A postal code's first two digits identify the province: `07` Baleares, `35` and `38` Canarias, `51` Ceuta, `52` Melilla, other `01`-`52` Peninsula, anything else unknown (not Peninsula).

## Self-consumption

Consumption rows may carry `surplusEnergyKWh`, `generationEnergyKWh`, `selfConsumptionEnergyKWh`. Contract detail carries `selfConsumptionTypeCode` (31-33, 41-43, 51-58, 61-64, 71-74, 77 in the public code list), `cau`, `installedCapacity`, `partitionCoefficient`. The client exposes them; it does not interpret them.

## Reactive energy

Penalised in 3.0TD and 6.XTD in every period except P6 when reactive exceeds 33 % of active (cos phi below 0.95). The client only exposes the values.

## Authorization model

A holder (titular) authorises a third party's NIF inside Datadis (up to about 2 years, needs renewal, not always instant). The third party queries with **its own** credentials plus `authorizedNif`, never with the holder's password.

## Public API (no authentication, v1-style only)

`/api-public/api-search`, `api-sum-search`, `api-search-auto`, `api-sum-search-auto`. Dates `YYYY/MM/DD`, `page` from 0, `pageSize` up to 2000, `community` mandatory, hourly totals `mi1`..`mi25`. Parameters and answer shapes are in [api-reference.md](api-reference.md).
