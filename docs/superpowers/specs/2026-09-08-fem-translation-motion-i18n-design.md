# FEM Translation, Motion and i18n Design

## Goal

Adopt the verified improvements supplied in the updated Figma and WordPress packages, make both plugin surfaces translatable in Italian and English, and introduce a safe, declarative GSAP motion foundation for Elementor imports.

## Scope and boundaries

- FEM remains a Figma-to-WordPress flow. It does not attempt a universal design-to-code conversion.
- Recognised semantic widgets are opt-in choices from Layer Mapping. A layer that cannot meet a widget's minimum data contract remains a container and produces an actionable note.
- Supported first-party semantic widgets are `google-maps`, `reviews`, `nested-accordion`, `image-carousel`, and `image-gallery`.
- Existing Figma and WordPress documents remain valid; newly added metadata is additive and ignored by older clients.
- No JavaScript source, URL, selector, or arbitrary CSS is accepted from Figma for motion.

## Translation improvements

The integration takes the user-supplied fixes in isolated groups:

1. Figma exporter/UI: viewport detection, duplicate sibling matching by stable position, hidden-layer and child-order preservation, progress reporting, resizable panel, and folded layer tree.
2. WordPress import boundary: concurrent asset upload safety and validation parity between exporter vocabulary and `SchemaValidator`.
3. Elementor projection: recognised widget fallbacks, responsive sizing/alignment/visibility, fixed-height/object-fit image handling, and per-widget minimum data checks.

Each group is merged only after its focused tests and the full cross-package suite pass. The local regression script from the supplied package is converted into focused PHPUnit/Node tests rather than used as an unmaintained second test harness.

## Motion architecture

Motion is an Elementor-only module in this phase.

### Contract

A node may carry optional `motion` metadata:

```json
{
  "preset": "fade-up",
  "trigger": "viewport",
  "durationMs": 600,
  "delayMs": 0,
  "easing": "power2.out",
  "once": true,
  "staggerMs": 0
}
```

Allowed presets: `fade`, `fade-up`, `fade-down`, `slide-left`, `slide-right`, `scale-in`, `reveal`, `stagger-children`. Allowed triggers: `viewport` and `load`. Values are bounded: duration 100–5000 ms, delay 0–5000 ms, stagger 0–1000 ms. Motion is disabled when `prefers-reduced-motion: reduce` is active.

### Storage and runtime

- Figma stores the descriptor in plugin data; the exporter normalizes it and the server schema validates it.
- Elementor settings retain the descriptor under FEM namespaced metadata plus a generated FEM class; they do not write into Elementor Custom Code.
- The WordPress plugin enqueues a locally bundled GSAP runtime only on documents containing FEM motion descriptors. It reads serialized JSON from data attributes, validates once more in the browser, and creates the selected timeline.
- Version 1 has no Gutenberg runtime, no custom selector, no arbitrary timeline, no third-party URL, and no editor-preview animation by default.

## Internationalisation

- WordPress uses text domain `figma-elementor-multimodal`. All human-facing PHP strings use WordPress translation functions and ship `languages/figma-elementor-multimodal-it_IT.po`/`.mo` plus a POT template; English remains the source locale.
- The Figma UI uses an internal keyed catalogue (`en`, `it`) selected from `figma.currentUser?.locale` when available, otherwise English. Missing keys always fall back to English.
- User-generated design text, layer names, page titles, URLs and server messages are never translated.

## Errors, observability and compatibility

- Invalid widget/motion descriptors return structured 4xx REST errors before staging.
- Approximate or incomplete widget conversion returns a visible import note, never silently changes content.
- Imports on sites without Elementor retain a validated snapshot but refuse Elementor projection with an actionable dependency error.
- Motion failure must leave imported Elementor content visible and usable without JavaScript.

## Verification

- Every behavior begins with a failing Node or PHPUnit test.
- Node: Figma extraction, UI catalog fallback, duplicate/hidden responsive selection, and motion descriptor normalization.
- PHP: schema validation, semantic widget outputs/fallbacks, asset concurrency, Elementor motion metadata, and translated admin rendering.
- Browser/runtime: a DOM-level motion runtime test with reduced-motion enabled and disabled.
- CI: existing Figma, PHP, static analysis, style checks, and real WordPress/MySQL integration all remain green.

## Team allocation

Independent read-only audits may run in parallel. Implementation tasks that touch a shared contract remain sequential.

| Work | Model / reasoning | Why |
| --- | --- | --- |
| Archive diff, catalogue and test inventory | Luna / low | Mechanical comparison and evidence collection. |
| Figma UI strings and Node tests | Luna / medium | Localised, bounded JavaScript/UI work. |
| WordPress gettext extraction/catalogue | Luna / medium | Conventional WordPress localization. |
| Widget mapping and responsive projection | Terra / high | Cross-layer behavior and compatibility. |
| Motion contract, schema and security review | Astra / xhigh | Persistent contract, client runtime and injection boundary. |
| Integration review and CI triage | Terra / high | Requires cross-package context but no new architecture. |

