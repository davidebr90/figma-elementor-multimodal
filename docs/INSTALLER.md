# FEM Setup: stato installer Windows, macOS e Linux

## Stato al 7 settembre 2026

FEM Setup è un wizard desktop in Tauri 2 che verifica un sito WordPress, genera un manifest Figma limitato al dominio scelto e conserva una precedente copia locale per il rollback. Il codice dell’app e i test sono nel repository; gli installer firmati per l’utente finale non sono ancora pubblicati.

| Piattaforma | Stato del codice | Distribuzione attuale | Da completare prima della release stabile |
| --- | --- | --- | --- |
| Windows | Build Tauri verificata localmente | MSI e NSIS x64 generati in locale, non firmati | firma Authenticode, test su installazione pulita, release firmata |
| macOS | Build Tauri configurata | Compilazione da sorgente o artifact CI di sviluppo | firma Developer ID, notarizzazione Apple, test Intel/Apple Silicon |
| GNU/Linux | Build Tauri configurata | Compilazione da sorgente o artifact CI di sviluppo | test dei pacchetti generati e compatibilità reale con le build Figma-Linux |

Il workflow GitHub costruisce artifact di sviluppo sulle tre piattaforme, ma non firma, non notarizza e non pubblica una release. Un artifact di sviluppo non va distribuito come installer fidato.

## Funzioni disponibili

- controllo sintattico dell’URL e del namespace REST FEM;
- HTTP consentito solo per loopback in modalità locale;
- manifest Figma generato con il solo dominio del sito configurato;
- preferenze URL locali senza credential, token o codici;
- preparazione atomica del pacchetto per sito;
- conservazione dell’ultima copia e pulsante di ripristino;
- rilevamento indicativo dei percorsi standard Figma e Figma-Linux.

## Funzioni non ancora incluse

- download e aggiornamenti firmati dal wizard;
- selettore manuale di una cartella Figma;
- report end-to-end che attesti l’esecuzione dentro Figma;
- firma/notarizzazione degli eseguibili;
- assistenza automatica per ogni formato di distribuzione Figma-Linux.

## Compilare da sorgente

Prerequisiti: Node.js 22+, Rust stabile e i prerequisiti Tauri della piattaforma.

```powershell
cd apps/installer
npm ci
npm test
npm run build
npm run tauri build
```

I bundle vengono prodotti in `apps/installer/src-tauri/target/release/bundle/`. Su Linux installare inoltre i pacchetti di sviluppo richiesti da WebKitGTK e Tauri; il workflow [installer.yml](../.github/workflows/installer.yml) contiene la lista usata dalla build Ubuntu.

## Uso del wizard

1. Avviare FEM Setup.
2. Inserire l’origine del sito senza percorso amministrativo, query, hash o credenziali.
3. Abilitare **Ambiente locale di sviluppo** solo per un URL loopback HTTP.
4. Selezionare **Verifica connessione** e correggere eventuali errori REST.
5. Chiudere FEM in Figma e confermarlo nel wizard.
6. Selezionare **Prepara i file**.
7. In Figma importare il manifest mostrato dal wizard.

Il wizard prepara un plugin locale; non installa automaticamente un plugin dentro Figma e non può attestare da solo che Figma abbia caricato il manifest. Quel passaggio deve essere verificato nell’app Figma.

Per il flusso completo consultare [INSTALLAZIONE.md](INSTALLAZIONE.md).
