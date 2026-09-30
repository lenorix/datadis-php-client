# Open questions

## Decisions pending from the maintainer

1. **Decided: v1-only functionality is included** (maintainer, after the first review). The v2 API has no equivalent for:
   - Authorization management: `new-authorization`, `cancel-authorization`, `list-authorization` (v1-style paths, listed only in a manual-derived reference, never captured, one implementation claims the listing does not exist).
   - The public API (`api-search`, `api-sum-search`, `api-search-auto`, `api-sum-search-auto`): no authentication, no success sample in any source, unknown response shape.
   
   Both are implemented from the manual-derived reference and tested with SYNTHETIC fixtures; mark them UNVERIFIED in the code docs until captured against the live API.
2. Decided: Guzzle is a required dependency and the default transport. Keeping the client PSR-18 agnostic is desirable but secondary: drop it if it causes problems or bugs.
3. Decided in the plan (revisit on request): decimals use `brick/math`, exposed as scaled strings.

## Unverified facts (do not hard-code without checking the live API)

- Real token lifetime (only the JWT `exp` claim can tell).
- Whether a repeated query really returns 429 and whether `Retry-After` is ever sent.
- Whether the repetition key ignores the endpoint (max-power vs reactive).
- Whether a per-CUPS daily quota exists beyond the identical-query rule.
- Real success bodies for reactive energy and max power (max-power `period` may be `"1"` or `"P1"`, and the unit is kW in practice).
- Quarter-hourly `time` format and row counts.
- Which point types really offer quarter-hourly data. The captured parameter description says types 1 and 2, and 3 for E-distribución; one sector note says Datadis widened quarter-hourly data in 2025, and an implementation that validated this locally had to relax its checks. The client does not refuse any point type: a wrong local refusal would leave no way around it.
- Whether `authorizedNif` is tolerated for own supplies, and which NIF formats (hyphens, spaces, lowercase) are accepted.
- Tolerated month range per call (multi-month) and the payload ceiling.
- Whether `-v2` responses differ from v1 in item field names (`accessFare` vs `accesFare`, `installedCapacity` vs `installedCapacityKW`).
- Canarias hour-label semantics.
- Why third-party-authorized consumption sometimes returns an empty `timeCurve`.
- Whether `get-groups-v2` exists (appears in one implementation only).
- Fixtures provenance: the contract-detail payload shapes are believed real; the max-power fixture is hand-made.
