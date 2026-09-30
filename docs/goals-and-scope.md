# Goals and scope

## Purpose

A PHP 8.4+ package that is **only the client side of Datadis**: the code and the helpers a consumer needs to read Datadis data and do something with it. It is framework-agnostic and domain-agnostic.

## In scope

- Authentication and token handling (expiry-aware, cacheable).
- Every endpoint of the private API in **both v1 and v2**: supplies, distributors with supplies, contract detail, consumption, max power (and reactive data, which exists only in v2). The caller picks the version (`ApiVersion`), v2 being the default.
- Functionality that only exists in v1 (maintainer decision): authorization management (`new-`, `cancel-`, `list-authorization`) and the public API (`api-search`, `api-sum-search`, `api-search-auto`, `api-sum-search-auto`). The public API answer shapes are known from the official manual's samples; the authorization answers are not (see [open-questions.md](open-questions.md)).
- Typed, immutable DTOs that also keep the raw payload.
- An exception taxonomy that tells the caller what is safe to retry.
- Generic helpers needed to interpret the data: month value object, hour-label handling, CUPS shape validation and normalisation, personal-data redaction, request fingerprinting for the 24 h rule, tariff-shape parsing.
- HTTP transport: Guzzle by default. The client type-hints PSR-18 so another client can be injected, but that agnosticism is kept only while it causes no bugs; if it does, Guzzle wins.

## Out of scope

- Queues, jobs, persistence, schedulers, alerts, UI.
- Billing, taxes, price comparison, business rules of any specific product.
- Framework integrations (a Laravel adapter, for instance, belongs in a separate package).
- The web-portal internal endpoints (`/api-private/supply-data/...`). They are undocumented and not the official API.

## Hard rules

1. Nothing in this repository may mention the private projects that inspired it, nor any git-ignored local file.
2. No real CUPS, NIF, credentials or personal data anywhere (code, tests, fixtures, docs). Use the fictitious values in [testing-strategy.md](testing-strategy.md).
3. The library has no filesystem side effects and never writes logs by itself.
4. Code, comments and docs are written in English. Spanish terms are kept only where translation loses precision (e.g. `comercializadora`, `distribuidora`).
5. Commit messages are plain descriptive sentences for a human reader. **No conventional commits** and **no co-author trailer**.

## Working process

1. Read and document (this folder).
2. Write a detailed plan (task list, classes, tests).
3. Implement with **TDD** (red, green, refactor) in Pest.
4. Review: run the whole suite, read the code, then add **property-based tests** with Eris to hunt for bugs.
5. Fix what the review finds and repeat step 4 until clean.
6. Commit per milestone. The maintainer reviews.
