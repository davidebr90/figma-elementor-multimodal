# Plugin Figma FEM

Questo pacchetto contiene il plugin di sviluppo Figma per Figma Elementor Multimodal.

Per un sito reale usare il manifest generato da **FEM Setup**: limita la rete al dominio scelto e precompila l’URL. Il manifest sorgente è dedicato allo sviluppo locale e non va riutilizzato in produzione.

1. In WordPress aprire **Strumenti → FEM Pairing** e generare i valori temporanei.
2. Preparare il pacchetto nel wizard `apps/installer`.
3. In Figma Desktop scegliere **Plugins → Development → Import plugin from manifest…**.
4. Avviare FEM, incollare URL, Pairing ID e codice, quindi importare una selezione.

Guida completa: [Figma](../../docs/FIGMA.md) · [Installazione](../../docs/INSTALLAZIONE.md) · [Limiti](../../docs/LIMITI.md).

Verifiche locali:

```powershell
node --test tests/workflow.test.cjs
node --check src/code.js
```
