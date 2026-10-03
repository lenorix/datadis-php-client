# Open questions

What is still unknown about Datadis, what the client assumes meanwhile, and the real request that would settle it. Everything already confirmed lives in [api-reference.md](api-reference.md) and [quirks-and-rules.md](quirks-and-rules.md); decisions live in [design-decisions.md](design-decisions.md). Remove an entry once it is settled and record the finding there.

| Question | What the client assumes meanwhile | How to settle it |
|----------|-----------------------------------|------------------|
| What does a group look like? | `name` and `description`, as documented. | `get-groups-v2` on an account with groups. |
| How is a partner agreement date written? | Returned as the text Datadis sends. | `partner-agreement-date` on an account with an agreement. |
| What do the calls that change data answer? | The raw text. | `new-authorization`, `cancel-authorization` and `partner-delete-user` with a NIF made for testing. |
| Is `authorizedNif` part of the 24 hour key for maximum power and reactive data? | No: the manual's key for maximum power leaves it out (section 4.4), and nothing official covers reactive data, so the guard takes that stricter reading. | A maximum power query with and then without `authorizedNif`; not available to the maintainers. |
| Is there a daily quota per CUPS beyond the identical-query rule? | No. A few clients space their calls (0.5 to 2 s, one 65 s between supplies) or stop for the day after repeated 429s without `Retry-After`; no official source states a rate limit, and the maintained clients do not pace. | Many different queries for one CUPS in a day. |
| Do the public sums take `page` and `pageSize`? | No, as the current documentation lists them. The 2023 manual and one client's specification send them, and that specification marks them required. | One `api-sum-search` with and one without `page=0&pageSize=10`. |
| Is the public filter `measurementType`, `measurementPointType` or `measurePointType`? | `measurementType`, as the current documentation says. A wrong name would most likely be ignored and the totals would not be filtered. | The same `api-sum-search` with each name, comparing `sumEnergy`. |
| Is `[previous, current]` accepted the day after `[current, current]` for the same supply? | Yes: the manual keys the rule on the parameters, `startDate` included, and the daily calls rely on it. | `[current, current]` one day and `[previous, current]` within the next 24 hours. |
| Does a 429 restart Datadis's 24 hour window? | Unknown, so the client never sends after a 429 what may have been asked within 24 hours. | A repeat refused with a 429, then the same query just over 24 hours after the first. |
| Does Datadis count a guarded query rejected with a 401? | Maybe, so it is not sent again after the new login. | A guarded query sent with an expired token, then the same query with a fresh one. |
| Does Datadis serve the month a contract starts in when it starts mid-month? | It is asked for; one client reports it is never served. A month before it is a 400 (verified) and is refused locally by the `...Of()` calls. | Consumption of the first month of a contract that started mid-month. |
| Does Datadis judge its 24 month window on the Madrid calendar? | Yes, for Canary Islands data too. | A query for the boundary month late on the last day of a month, Canary Islands time. |
| Do Canary Islands hour labels follow Canary time? | Yes, when the client is given `Atlantic/Canary`. | Consumption of a Canary Islands supply around its daylight saving change. |
| Why did third-party consumption come back empty in one report while supplies and contract worked? | Probably a wrong `distributorCode`: a wrong but existing code answers an empty list (verified). | Not reproduced with real third-party data, which answered normally. |

Settled from the official sources (October 2026), with no capture available: the quarter-hourly labels, read as Datadis's own portal reads them (the end of each quarter, the other convention still recognised), and the shape of a reactive answer with data, from the web documentation. See [quirks-and-rules.md](quirks-and-rules.md) and [api-reference.md](api-reference.md).

Settled by the October 2026 capture and moved to [quirks-and-rules.md](quirks-and-rules.md): the 429 and its missing `Retry-After`, maximum power and reactive data colliding, the v2 envelope keys, the authorization and partner answers, the public API needing the token, a 10 month range in one call, and how `authorizedNif` is written.
