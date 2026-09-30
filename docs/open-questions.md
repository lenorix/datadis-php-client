# Open questions

## Decisions taken with the maintainer

1. **v1-only functionality is included.** The v2 API has no equivalent for authorization management (`new-authorization`, `cancel-authorization`, `list-authorization`) nor for the public API. Both are documented on the official website; the public API answers are known from the official manual's samples, the authorization answers are not.
2. The partner programme endpoints (`partner-user-list`, `partner-delete-user`, `partner-agreement-date`) and `get-groups-v2` are included because the official documentation lists them. Their answers are kept raw where the documentation does not describe them.
3. Guzzle is a required dependency and the default transport. Keeping the client PSR-18 agnostic is desirable but secondary: drop it if it causes problems or bugs.
4. Decimals use `brick/math`, exposed as scaled strings.

## Resolved by the official documentation

- `get-groups-v2` exists (answer `name`, `description`).
- The public API answer shapes (official samples; see [api-reference.md](api-reference.md)).
- The public API `measurementType` is optional, sums take no paging, and a `groupByPostalCode` parameter exists.
- The 24 hour key of maximum power has no `authorizedNif` (official manual); the guard follows it.
- Maximum power `period` is `"1"`..`"6"` or `VALLE`/`LLANO`/`PUNTA` (official documentation and sample).
- Supplies return the 22 character CUPS (measured in production).

## Unverified facts (do not hard-code without checking the live API)

- Real token lifetime (only the JWT `exp` claim can tell; the manual says nothing and no measurement was ever captured).
- Whether a repeated query really returns 429 and whether `Retry-After` is ever sent (the status is documented; the repeat behaviour was never observed).
- Whether the repetition key ignores the endpoint (max power vs reactive): reported by one implementation, not documented.
- Whether a per-CUPS daily quota exists beyond the identical-query rule.
- The unit of `maxPower`: the official documentation says W, household data seen by several implementations only makes sense in kW. The client calls it kW and keeps the raw value.
- The v2 envelope key of each answer is known from a captured specification, not from the official documentation, which lists only item fields and `distributorError`.
- Answers of the authorization and partner endpoints.
- Whether the public API refuses calls without the token (the manual asks for it; not tested).
- Quarter-hourly `time` format and row counts.
- Which point types really offer quarter-hourly data. The captured parameter description says types 1 and 2, and 3 for E-distribución; a sector note says Datadis widened quarter-hourly data in October 2025, and an implementation that validated this locally had to relax its checks. The client does not refuse any point type: a wrong local refusal would leave no way around it.
- Whether `authorizedNif` is tolerated for own supplies (the manual says it must not be added), and which NIF formats are accepted (a NIF without its final letter is reported to give 401).
- Tolerated month range per call (multi-month) and the payload ceiling (the manual's own example asks for 13 months of maximum power).
- Canarias hour-label semantics.
- Why third-party-authorized consumption sometimes returns an empty `timeCurve`.
