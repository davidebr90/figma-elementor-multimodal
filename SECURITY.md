# Security policy

This beta is designed for controlled testing before production use. Do not install it on an internet-facing production site without reviewing the configuration and the [deployment guidance](docs/en/SECURITY.md).

## Reporting a vulnerability

Use GitHub’s private security-advisory/reporting flow for this repository when it is available. Do not include passwords, pairing codes, bearer credentials, private keys, or real customer data in an issue, discussion, screenshot, or log.

## Supported line

Only the latest `0.1.x` beta line is maintained. Security fixes may require a schema migration or pairing renewal.

## Scope

The public repository intentionally contains no production endpoint, credential, user data, or signing material. The installer generates a manifest for a specific site locally; it does not upload that configuration to this repository.
