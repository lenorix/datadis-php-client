# Open questions

What is still unknown about Datadis, what the client assumes meanwhile, and the real request that would settle it. Everything already confirmed lives in [api-reference.md](api-reference.md) and [quirks-and-rules.md](quirks-and-rules.md); decisions live in [design-decisions.md](design-decisions.md). Remove an entry once it is settled and record the finding there.

| Question | What the client assumes meanwhile | How to settle it |
|----------|-----------------------------------|------------------|
| What does quarter-hourly data look like on a point type 1, 2 or 3 supply? | Two conventions are possible: the end of each quarter (`00:15`..`24:00`), or the hour that ends plus the minutes the quarter starts at (`01:00`..`24:45`), which one implementation in production assumes. The client tells them apart in each answer and leaves the rows without an interval when an answer cannot tell. | Quarter-hourly consumption of a point type 1, 2 or 3 supply, ideally a month with a daylight saving change. |
| What does a reactive answer with data look like? | Read as documented, with the verified `codeDescription` key. | Reactive data of a 3.0TD or larger supply. |
| What does a group look like? | `name` and `description`, as documented. | `get-groups-v2` on an account with groups. |
| How is a partner agreement date written? | Returned as the text Datadis sends. | `partner-agreement-date` on an account with an agreement. |
| What do the calls that change data answer? | The raw text. | `new-authorization`, `cancel-authorization` and `partner-delete-user` with a NIF made for testing. |
| Is `authorizedNif` part of the 24 hour key for maximum power and reactive data? | No (the manual does not list it), so the guard treats two such queries that differ only in it as the same. | A maximum power query with and then without `authorizedNif`. |
| Is there a daily quota per CUPS beyond the identical-query rule? | No. | Many different queries for one CUPS in a day. |
| Does Datadis judge its 24 month window on the Madrid calendar? | Yes, for Canary Islands data too. | A query for the boundary month late on the last day of a month, Canary Islands time. |
| Do Canary Islands hour labels follow Canary time? | Yes, when the client is given `Atlantic/Canary`. | Consumption of a Canary Islands supply around its daylight saving change. |
| Why did third-party consumption come back empty in one report while supplies and contract worked? | Probably a wrong `distributorCode`: a wrong but existing code answers an empty list (verified). | Not reproduced with real third-party data, which answered normally. |

Settled by the October 2026 capture and moved to [quirks-and-rules.md](quirks-and-rules.md): the 429 and its missing `Retry-After`, maximum power and reactive data colliding, the v2 envelope keys, the authorization and partner answers, the public API needing the token, a 10 month range in one call, and how `authorizedNif` is written.
