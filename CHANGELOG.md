# Changelog

## Unreleased — FEM 2.0 beta hardening

### Italiano

- Verifiche REST reali con WordPress e database in CI Linux, inclusi pairing, staging, retry e commit idempotente.
- Validazione più rigorosa degli asset REST e rifiuto dei documenti privi della revisione Figma richiesta.
- Normalizzazione dei metadati responsive legacy e applicazione esplicita di tipografia, geometria immagine, paint e bordi nei breakpoint Elementor.
- Correzione di una race condition: dopo upload concorrenti lo staging ricalcola gli asset mancanti prima di esporre lo stato o consentire il commit.
- Supporto esplicito per la mappatura **Reviews** nel plugin Figma e conversione delle card testuali leggibili in slider recensioni Elementor.
- Pannello Figma localizzato in italiano e inglese, con fallback inglese deterministico per le lingue non ancora disponibili.

### English

- Real REST coverage with WordPress and a database in Linux CI, including pairing, staging, retry, and idempotent commit.
- Stricter REST asset validation and rejection of documents missing the required Figma revision.
- Legacy responsive-metadata normalization plus explicit Elementor breakpoint handling for typography, image geometry, paint, and borders.
- Fixed a race condition: staging recomputes missing assets after concurrent uploads before reporting status or allowing commit.
- Explicit **Reviews** mapping in the Figma plugin and readable review-card projection into Elementor review sliders.
- Figma panel localized in Italian and English, with deterministic English fallback for locales not yet available.

## v2.0.0-beta.1 — 2026-09-07

### Italiano

- Flusso Figma → WordPress con schema FEM 1.1.
- Pairing con credenziali bearer a scadenza e scope limitati.
- Import nativo Elementor e Gutenberg.
- Responsive desktop/tablet/mobile per layout, paint e bordi.
- Mapping FEM stabile tra nodi Figma, classi CSS ed elementi WordPress.
- Validazione asset, JSON, schema e strutture Elementor.
- Protezione da collisioni ID, append, replace e commit idempotenti.
- Migrazione automatica dello schema database durante il bootstrap.
- Wizard Tauri con build Windows MSI/NSIS verificata localmente.
- Documentazione italiana e inglese.

Questa è una beta per ambienti di staging. Gli installer Windows non sono firmati; le build macOS/Linux e la compatibilità estesa Figma-Linux richiedono runner nativi e non sono ancora certificate. Il reimport WordPress → Figma non è disponibile.

### English

- Figma → WordPress flow using FEM schema 1.1.
- Pairing with expiring bearer credentials and limited scopes.
- Native Elementor and Gutenberg imports.
- Desktop/tablet/mobile support for layout, paint and borders.
- Stable FEM mapping between Figma nodes, CSS classes and WordPress elements.
- Asset, JSON, schema and Elementor structure validation.
- Collision-safe IDs, append, replace and idempotent commits.
- Automatic database schema migration during bootstrap.
- Tauri wizard with locally verified Windows MSI/NSIS builds.
- Italian and English documentation.

This is a staging-oriented beta. Windows installers are unsigned; macOS/Linux builds and broader Figma-Linux compatibility require native runners and are not certified yet. WordPress → Figma reverse import is not available.
