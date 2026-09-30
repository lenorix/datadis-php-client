# Security Policy

## Supported versions

Security fixes are released for the latest minor version of the latest major release.

## Reporting a vulnerability

Please do **not** open a public issue. Report it privately through GitHub's [private vulnerability reporting](https://github.com/lenorix/datadis-client/security/advisories/new), or by email to jesushdez@protonmail.com.

Include what is affected, how to reproduce it and the impact you expect. Do not include real credentials, tokens, CUPS, NIF or consumption data: use made-up values.

You will get an answer within a week. Once a fix is released, the advisory is published with credit to you unless you prefer otherwise.

## What this package does to protect data

- Credentials go in the login request body, never in URLs, and the password and the token are hidden from `var_dump`, `print_r`, `var_export` and serialisation.
- Exception messages and details are redacted of CUPS, NIF, NIE, CIF and tokens, and sensitive parameters are hidden from stack traces.
- Only HTTPS base URLs are accepted, TLS verification is never disabled, and the package never writes logs or files.
