# Project knowledge base

Internal notes for maintainers (human or AI) of `lenorix/datadis-client`. They capture what we learned about the Datadis API so future work does not have to rediscover it.

| File | Content |
|------|---------|
| [goals-and-scope.md](goals-and-scope.md) | What the package is, what it is not, and the working process. |
| [api-reference.md](api-reference.md) | Datadis private API v2: auth, endpoints, parameters, response fields. |
| [quirks-and-rules.md](quirks-and-rules.md) | Real-world behaviour: 24 h rule, hour labels, DST, transport, errors. |
| [domain-knowledge.md](domain-knowledge.md) | Spanish electricity concepts needed to interpret Datadis data. |
| [design-decisions.md](design-decisions.md) | Architecture: PSR interfaces, token cache, exceptions, retries, DTOs. |
| [testing-strategy.md](testing-strategy.md) | TDD + property-based testing rules, fixture provenance. |
| [open-questions.md](open-questions.md) | Unverified facts and decisions pending. |
| [implementation-plan.md](implementation-plan.md) | The milestones the package was built in. |
| [review-findings.md](review-findings.md) | Findings of the review milestone and their status. |

Evidence levels used across these files:

- **VERIFIED**: observed in real captured responses.
- **SPEC**: from a captured request/response specification or the official manual.
- **REPORTED**: stated by one third-party implementation, not confirmed.
- **UNVERIFIED**: assumption. Do not hard-code without checking the live API.
