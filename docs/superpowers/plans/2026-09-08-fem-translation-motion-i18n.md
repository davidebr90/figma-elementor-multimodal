# FEM Translation, Motion and i18n Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Safely integrate verified Figma/Elementor translation improvements, bilingual UI, and an opt-in GSAP motion foundation.

**Architecture:** Preserve the canonical FEM document as the only cross-package contract. Add additive, validated metadata for semantic widgets and motion; project it only to Elementor through namespaced metadata and a local runtime.

**Tech Stack:** Figma Plugin API, Node test runner, PHP 8.3, WordPress 7.1, Elementor, GSAP, PHPUnit, PHPStan, PHPCS.

**Spec:** `docs/superpowers/specs/2026-09-08-fem-translation-motion-i18n-design.md`

## Global Constraints

- No arbitrary JS, CSS, selectors or remote runtime URLs from Figma.
- Preserve v1.0/v1.1 import compatibility.
- Motion must honor `prefers-reduced-motion` and fail open.
- Use a failing test before each behavior change.
- Do not merge to `main` or release branches without explicit approval.

---

### Task 1: Evidence-led merge of importer reliability fixes

**Files:** `packages/figma-plugin/src/code.js`, `packages/figma-plugin/src/extraction/responsive.js`, `packages/wordpress-plugin/src/Application/ImportService.php`, matching Node/PHP tests.

- [ ] Write focused failing tests for duplicate sibling matching, hidden responsive nodes, and concurrent asset attachment.
- [ ] Run Node/PHP tests and confirm each fails against the current behavior.
- [ ] Port only the matching/exporter and asset-store fixes from the supplied archives.
- [ ] Run `npm test`, PHP unit tests, PHPStan, PHPCS, and `git diff --check`.
- [ ] Commit: `fix: harden responsive matching and asset uploads`.

### Task 2: Figma panel adoption and localisation foundation

**Files:** `packages/figma-plugin/src/ui/index.html`, `packages/figma-plugin/src/code.js`, new `src/ui/i18n.js`, Node UI tests.

- [ ] Write failing UI tests for English fallback and Italian labels.
- [ ] Run the focused Node test and confirm it fails.
- [ ] Add a keyed `en`/`it` catalogue and replace static UI labels; port tested panel progress, resize and outline behavior.
- [ ] Run the Figma suite and bundle build.
- [ ] Commit: `feat: localize and improve Figma import panel`.

### Task 3: WordPress localisation

**Files:** `packages/wordpress-plugin/src/Plugin.php`, PHP sources with admin copy, `languages/`, PHPUnit tests.

- [ ] Write a failing test that checks the plugin text domain is loaded and pairing copy is translatable.
- [ ] Replace literal UI strings with `esc_html__`, `esc_attr__`, or `sprintf` equivalents using the FEM text domain.
- [ ] Add POT and Italian catalogue; compile MO deterministically in the release workflow.
- [ ] Run PHP tests, static analysis and code style checks.
- [ ] Commit: `feat: add Italian and English plugin localization`.

### Task 4: Semantic widget contracts

**Files:** Figma widget classifier, `SchemaValidator.php`, `Elementor/Transpiler.php`, unit fixtures/tests.

- [ ] Write failing fixtures for reviews/maps/accordion/carousel valid conversion and missing-data fallback notes.
- [ ] Add each widget to exporter and validator together; reject or downgrade incomplete descriptors predictably.
- [ ] Project only native Elementor controls supported by each widget; retain unhandled children as containers where required.
- [ ] Run all Node/PHP tests and real WordPress integration test.
- [ ] Commit: `feat: add guarded semantic Elementor widgets`.

### Task 5: Motion contract and server validation

**Files:** new motion normalizer, `SchemaValidator.php`, fixtures, Node/PHP tests.

- [ ] Write failing tests for one valid descriptor and invalid preset, trigger, duration, delay and stagger bounds.
- [ ] Implement the allowlisted descriptor and validate it at extraction and staging boundaries.
- [ ] Store only namespaced motion metadata in transpiled Elementor elements.
- [ ] Run focused and full suites.
- [ ] Commit: `feat: add validated FEM motion descriptors`.

### Task 6: Local GSAP runtime

**Files:** new WordPress motion runtime asset, enqueue integration, runtime tests, build/release configuration.

- [ ] Write failing tests for local asset registration, descriptor parsing, reduced-motion no-op, and visible fallback after runtime error.
- [ ] Bundle a pinned GSAP dependency locally; enqueue only when a page contains FEM motion metadata.
- [ ] Implement only allowlisted presets and viewport/load triggers.
- [ ] Run all suites plus a browser-level runtime fixture.
- [ ] Commit: `feat: add opt-in GSAP motion runtime`.

### Task 7: Full integration review

**Files:** docs, changelog, verification log.

- [ ] Run Node, PHP, static analysis, lint, WordPress/MySQL integration and CI.
- [ ] Verify no secrets, generated vendor files, or user workspace artifacts are staged.
- [ ] Record supported widgets, motion limits, accessibility behavior and remaining gaps in Italian and English documentation.
- [ ] Commit: `docs: record FEM translation and motion verification`.
