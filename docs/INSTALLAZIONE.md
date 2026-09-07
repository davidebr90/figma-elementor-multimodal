# Installazione e primo collegamento

## Prima di iniziare

Servono WordPress 7.0+, PHP 8.3+, una licenza Figma valida e attiva, Figma Desktop e una pagina di test. Per importare in Elementor serve Elementor 3.20+; Gutenberg non richiede Elementor.

Usare HTTPS sul sito reale. La modalità HTTP del wizard è riservata a `localhost`, `127.0.0.1` e `[::1]` durante lo sviluppo locale.

## 1. Preparare WordPress

1. Scaricare l’archivio WordPress della release, oppure creare un pacchetto dal sorgente come descritto in [SVILUPPO.md](SVILUPPO.md).
2. In WordPress aprire **Plugin → Aggiungi nuovo → Carica plugin** e caricare lo ZIP.
3. Attivare **Figma Elementor Multimodal**.
4. Verificare che il sito non mostri l’avviso su PHP e, se si userà Elementor, quello sulla sua versione.
5. Aprire **Strumenti → FEM Pairing**.

L’attivazione crea solo tabelle con prefisso WordPress `fem_`; la disattivazione non cancella design, snapshot o audit. Eseguire un backup del database prima del primo test su un sito già esistente.

## 2. Creare il pairing

1. Nella pagina **FEM Pairing** scegliere la durata della connessione Figma: **8 ore** oppure **7 giorni** per una workstation fidata.
2. Selezionare **Genera codice di pairing**.
3. Copiare, con i pulsanti presenti nella pagina, **URL del sito**, **Pairing ID** e **codice**.
4. Il codice è monouso e scade dopo dieci minuti. Se scade, generarne uno nuovo.

Il codice non compare di nuovo dopo un refresh. Il bearer emesso dopo lo scambio resta soltanto nello storage privato del plugin Figma e può essere rinnovato prima della scadenza.

## 3. Preparare il plugin Figma

La via consigliata è FEM Setup:

1. Aprire l’app FEM Setup.
2. Inserire l’origine del sito, ad esempio `https://example.com`, senza `/wp-admin`, credenziali, query o frammenti.
3. Selezionare **Verifica connessione**. Il controllo conferma che WordPress e il namespace FEM rispondono; non certifica ancora l’import in Figma.
4. Chiudere FEM se è aperto in Figma, confermarlo nel wizard e selezionare **Prepara i file**.
5. Copiare il percorso del manifest generato.

FEM Setup scrive una copia locale per sito e mette nel manifest soltanto il dominio scelto. Non usare il manifest sorgente con domini permissivi per un sito pubblico.

## 4. Importare in Figma Desktop

1. In Figma Desktop aprire **Plugins → Development → Import plugin from manifest…**.
2. Selezionare il `manifest.json` indicato da FEM Setup.
3. Avviare **Figma Elementor Multimodal** da **Plugins → Development**.
4. Incollare URL, Pairing ID e codice; selezionare **Connetti a WordPress**.
5. Selezionare un frame o un componente. Se disponibili, associare le varianti desktop, tablet e mobile prima dell’import.
6. Scegliere la pagina WordPress e la destinazione: Elementor oppure Gutenberg.
7. Avviare l’import e attendere il riepilogo di asset, avvisi e snapshot.

## 5. Verificare il risultato

1. Aprire la pagina di test in WordPress.
2. Per Elementor, aprire l’editor e controllare struttura, testo, immagini, margini, padding, bordi e punti responsive.
3. Per Gutenberg, controllare il blocco **FEM Scene** sia nell’editor sia nel front-end.
4. Provare desktop, tablet e mobile. Correggere in WordPress dove necessario, poi pubblicare solo dopo un controllo visivo completo.

Per errori di connessione o import, consultare [SICUREZZA.md](SICUREZZA.md), [LIMITI.md](LIMITI.md) e il log del server WordPress senza pubblicare credenziali o codici temporanei.
