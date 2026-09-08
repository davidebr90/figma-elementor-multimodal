# Checklist di release — FEM v2.0.0-beta.1 / Release checklist

Questa checklist descrive la build pubblicata `v2.0.0-beta.1`. Le modifiche successive sono registrate nella sezione **Unreleased** del [changelog](../CHANGELOG.md) e restano nella linea FEM 2.0 beta finché non viene creato un nuovo tag.

## Italiano

- [x] Test automatici Figma, WordPress, installer Node e Rust verdi.
- [x] Runtime Docker + WordPress + Elementor verificato.
- [x] Windows MSI e NSIS x64 generati localmente.
- [x] Hash SHA-256 degli artifact registrati.
- [x] Nessun secret nel codice versionato.
- [x] Documentazione italiana e inglese aggiornata.
- [ ] Test installazione Windows su macchina pulita.
- [ ] Firma Authenticode del bundle Windows.
- [ ] Build e test nativi macOS Intel/Apple Silicon.
- [ ] Firma Developer ID e notarizzazione macOS.
- [ ] Build Linux AppImage/deb e test su Figma-Linux reale.
- [ ] Test end-to-end dal plugin Figma Desktop distribuito.
- [ ] Pubblicazione GitHub Release firmata.

## English

- [x] Figma, WordPress, Node installer and Rust automated tests pass.
- [x] Docker + WordPress + Elementor runtime verified.
- [x] Windows x64 MSI and NSIS bundles generated locally.
- [x] SHA-256 hashes recorded for the artifacts.
- [x] No secrets in tracked source files.
- [x] Italian and English documentation updated.
- [ ] Clean-machine Windows installation test.
- [ ] Authenticode signing for the Windows bundle.
- [ ] Native macOS Intel/Apple Silicon builds and tests.
- [ ] Developer ID signing and macOS notarization.
- [ ] Linux AppImage/deb build and real Figma-Linux test.
- [ ] End-to-end test from the distributed Figma Desktop plugin.
- [ ] Signed GitHub Release publication.

Unsigned development artifacts must not be presented as trusted production installers.
