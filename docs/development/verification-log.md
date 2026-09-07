# FEM verification log

## Baseline — 2026-09-07

- Baseline commit: `0237ea7`
- Worktree: `codex/fem-task-00`
- Environment: Windows PowerShell; Node.js 24.13.1; PHP 8.3.31; Docker Desktop Linux engine; Rust toolchain available.
- Figma plugin syntax: PASS (`node --check packages/figma-plugin/src/code.js`).
- Figma plugin tests: PASS — 16 tests, 0 failures.
- WordPress PHPUnit: PASS — 60 tests, 145 assertions, 1 intentional live-WordPress migration skip.
- PHPStan: PASS — no errors.
- PHPCS: PASS — no errors.
- Gutenberg block roundtrip: PASS — 7 blocks valid; repeated save, text edit, links, FEM identity and responsive overrides preserved.
- Installer Node tests: PASS — 10 tests, 0 failures.
- Installer Rust tests: PASS — 2 tests, 0 failures.
- Docker Compose configuration: PASS.
- Artifact hashes: not generated for this baseline; no release artifact was produced.
- Notes: Composer dependencies were installed in the isolated worktree through the `composer:2` Docker image because the host PHP lacked ZIP support. No product source files were changed by dependency setup.

## Task 1 — 2026-09-07

- Commit: pending until integration gate.
- Figma characterization: PASS — 17 tests, 0 failures.
- WordPress schema characterization: PASS — 4 tests, 9 assertions, including desktop/tablet/mobile fixture preservation.
- Production behavior changed: no; only fixtures, tests and this verification record were added.

## Task 2 — 2026-09-07

- Figma manifest policy: PASS — 17 tests, 0 failures.
- Private Figma API flag: removed from the development manifest.
- Wildcard network access: retained only in the explicitly development-only source manifest; generated site manifests remain origin-scoped.
- Production behavior changed: manifest permissions only; import/pairing transport unchanged.

## Task 3A — 2026-09-07

- Figma bundle: PASS — `npm run build` generated `dist/code.js`; bundle syntax check passed.
- Bundle runtime smoke test: PASS — Figma UI entrypoint initialized; Figma suite 18/18.
- Integration scope: preparatory only. Development manifest and installer still use `src/code.js` until module extraction and packaging integration are separately verified.

## Task 3B — 2026-09-07

- Extracted pure `layoutOf` and `solidColor` helpers under `packages/figma-plugin/src/extraction/`.
- Added a bundle bridge with legacy fallback, so source development remains executable without bundling.
- Figma extraction/helper tests: PASS — 20 tests, 0 failures, no warnings.
- Bundle build and syntax check: PASS.
- Integration scope: Elementor/WordPress code unchanged; installer still consumes the legacy-compatible source entry.

## Task 3C — 2026-09-07

- Extracted typography weight normalization into `packages/figma-plugin/src/extraction/typography.js`.
- Legacy code retains a fallback and the production bundle uses the extracted helper through the bridge.
- Figma tests: PASS — 21 tests, 0 failures; bundle syntax check passed.

## Task 3D — 2026-09-07

- Extracted responsive parsing, normalized matching keys and hierarchy/type matching into `packages/figma-plugin/src/extraction/responsive.js`.
- Added coverage for malformed plugin data, unknown viewports, reordered hierarchy and type changes.
- Figma tests: PASS — 23 tests, 0 failures; bundle syntax check passed.

## Task 3E — 2026-09-07

- Extracted deterministic PNG asset identifiers, descriptors and payload validation.
- Unsupported formats, malformed SHA-256 values and invalid byte sizes are rejected before descriptor creation.
- Figma tests: PASS — 25 tests, 0 failures; bundle syntax check passed.
- SVG import, sanitization and remote asset fetching remain intentionally disabled and require a separate security-gated task.

## Task 8A — 2026-09-07

- Hardened both in-memory and WordPress asset stores against unsupported MIME types.
- Direct calls can no longer bypass the PNG/JPEG allowlist and fall back to an unsafe `.bin` upload.
- Targeted PHPUnit: PASS — 1 test, 2 assertions; PHPStan and PHPCS: PASS.

## Task 4A — 2026-09-07

- Added additive FEM schema `1.1.0` acceptance while preserving `1.0.0`.
- Added bounded validation for optional `styles`, `responsive` and `bindings` metadata.
- Binding validation checks ownership, source hash, reachable FEM node and duplicate property mappings.
- Schema validator tests: PASS — 6 tests, 12 assertions.

## Task 4B — 2026-09-07

- Added authenticated `/capabilities` endpoint.
- Figma negotiates v1.1 only when WordPress advertises it; failed or missing negotiation falls back to v1.0.
- Advertised targets remain Elementor and Gutenberg; reverse sync is not advertised.
- Figma tests: PASS — 26 tests, 0 failures.

## Task 4C — 2026-09-07

- Figma emits v1.1 metadata only after successful negotiation: empty style tokens, canonical viewport list and read-only node bindings.
- Each binding carries the source node, FEM node, `node` property, `figma` ownership and a SHA-256 source hash.
- v1.0 output remains unchanged when negotiation is unavailable.
- Figma tests: PASS — 26 tests, 0 failures; bundle syntax check passed.
