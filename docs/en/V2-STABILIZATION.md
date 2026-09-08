# FEM 2.0 stabilization

This document separates implemented beta capabilities from the checks still required before FEM 2.0 can be called stable. Each item needs repeatable evidence before its status changes.

| Area | Beta state | Stable release criterion |
| --- | --- | --- |
| Figma → WordPress contract | Schema 1.1, capability negotiation and validation are available | Compatible client/server fixtures and verified migration |
| Pairing and credentials | Scopes, expiry, renewal and revocation are available | Interrupted-network, renewal-during-import and concurrent-revocation tests |
| Staging and commit | Asset manifest, expiry, integrity and idempotency are available | No partial content after errors, duplicate requests or retries |
| Elementor and Gutenberg | Native import and namespaced IDs are available | Tests on real WordPress, Elementor and Gutenberg with append, replace and duplication |
| Responsive output | Desktop/tablet/mobile layout, paint, borders, typography and image geometry are available; the client also normalizes legacy grouped `fem-responsive` `layout`/`style`/`text` metadata | Visual comparisons at target breakpoints and intermediate widths for supported cases |
| Complex content | No universal prototype or JavaScript conversion | Accessible declarative interaction catalogue; isolated mode only after validation |
| Installer | Windows build exists; macOS/Linux pipeline exists | Native builds, clean installation, update, signing and target-platform tests |
| Docker runtime on Windows | Compose environment exists, but the local host remains unstable; real WordPress migration is covered in Linux CI | Repeated successful start/stop cycles; extend runtime CI to pairing and import |

## Change rules

1. Every new plugin behavior starts with a test that fails before the implementation.
2. REST endpoints turn invalid input into structured 4xx responses; validation exceptions must not reach the client as 500 errors.
3. Imported values keep origin, node identity and property identity so future conflicts and controlled reimport are possible.
4. CSS classes are namespaced derived names; persistent FEM identities remain the primary key.
5. WordPress → Figma reverse import is outside 2.0. Only provenance and binding data needed for its future design are prepared now.

## Release sequence

1. Consolidate CI and REST-boundary tests.
2. Add repeatable Linux runtime tests and repeat local tests once Docker Desktop is available.
3. Extend responsive mapping with visual fixtures and approximation warnings.
4. Add conflict and reset UX for locally customized Elementor and Gutenberg properties.
5. Validate installation and update on Windows, macOS and Linux.
6. In the same commit as every functional change, update the affected user guide and the `Unreleased` changelog section. Update the checklist, tag, and release only after all required evidence is green.
