# Open questions

What is still unknown about Datadis, what the client assumes meanwhile, and the real request that would settle it. Everything already confirmed lives in [api-reference.md](api-reference.md) and [quirks-and-rules.md](quirks-and-rules.md); decisions live in [design-decisions.md](design-decisions.md). Remove an entry once it is settled and record the finding there.

| Question | What the client assumes meanwhile | How to settle it |
|----------|-----------------------------------|------------------|
| Does repeating a consumption query within 24 hours really get HTTP 429, and is `Retry-After` ever sent? | 429 is `RepetitionWindowException` and is never retried; `Retry-After` is read only by `RetryingClient`, for endpoints that are not guarded. | Send the same consumption query twice (it burns that query for a day: use a month you do not need). |
| Does the repetition key ignore the endpoint, so maximum power and reactive with equal parameters collide? | Yes (the stricter reading): the guard treats them as the same query. | Query maximum power, then reactive data with the same parameters. |
| Is there a daily quota per CUPS beyond the identical-query rule? | No. | Many different queries for one CUPS in a day. |
| Does Datadis judge its 24 month window on the Madrid calendar? | Yes, for Canary Islands data too. | A query for the boundary month late on the last day of a month, Canary Islands time. |
| What envelope key does each v2 answer use? | The keys of a captured request specification (`supplies`, `contract`, `timeCurve`, `maxPower`, `reactiveEnergy`, `distExistenceUser`); a bare list is accepted too. Every real answer so far was on v1 paths. | Call every `-v2` endpoint once. |
| What do the authorization, group and partner calls answer? | Authorizations and groups are decoded tolerantly; partner calls and authorization changes are returned raw. | Call each once with an account that has them (the captured account has the partner role). |
| Does the public API refuse calls without the token? | `PublicApiClient` sends the token when given a `DatadisConfig`, and nothing when given only `ConnectionSettings`. | One public search with and one without the token. |
| What does quarter-hourly data look like, and which point types get it? | Labels are the end of a 15 minute interval (`00:15`..`24:00`); no point type is refused locally. A type 5 supply answers an empty list. | Quarter-hourly consumption of a point type 1, 2 or 3 supply. |
| What is the largest month range per call? | One month per request by default (`MonthPlanner`); three months are verified to work. | A 12 month consumption query and a 13 month maximum power query. |
| Which NIF formats are accepted? | NIFs are sent trimmed and uppercase. A NIF without its final letter is reported to give 401. | Login and `authorizedNif` with a lowercase NIF, and with spaces or a dash. |
| Do Canary Islands hour labels follow Canary time? | Yes, when the client is given `Atlantic/Canary`. | Consumption of a Canary Islands supply around its daylight saving change. |
| Why did third-party consumption come back empty in one report while supplies and contract worked? | Probably a wrong `distributorCode`: a wrong but existing code answers an empty list (verified). | Not reproduced with real third-party data, which answered normally. |
