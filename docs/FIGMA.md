# Configurazione Figma

## Requisiti

FEM richiede Figma Desktop, un account con licenza Figma attiva e l’uso dei plugin di sviluppo. Il plugin non dipende da API private per l’import; il manifest preparato dal wizard limita le richieste di rete al solo sito WordPress configurato.

Su Linux, installare prima [Figma-Linux](https://github.com/Figma-Linux/figma-linux) e verificare nella propria build che il menu **Plugins → Development** accetti il manifest locale.

## Importare il manifest

1. Preparare i file con FEM Setup.
2. In Figma Desktop scegliere **Plugins → Development → Import plugin from manifest…**.
3. Selezionare il `manifest.json` creato dal wizard, non quello sorgente nel repository.
4. Avviare FEM da **Plugins → Development**.

Il wizard conserva una copia precedente per sito. Prima di usare **Ripristina versione precedente**, chiudere FEM in Figma e confermare la chiusura nel wizard.

## Lingua del pannello

Il pannello FEM usa italiano quando il browser incorporato di Figma segnala un locale italiano; per le altre lingue usa l’inglese come fallback. I nomi dei livelli, i testi del design e i messaggi ricevuti dal sito non vengono tradotti dal plugin.

## Collegare WordPress

1. Generare un codice in **Strumenti → FEM Pairing** nel sito WordPress.
2. Copiare URL del sito, Pairing ID e codice.
3. Incollare i valori in FEM e selezionare **Connetti a WordPress**.
4. Se l’operazione riesce, il codice non serve più: il plugin conserva solo la credenziale temporanea nel proprio storage.

Quando la credenziale è vicina alla scadenza il plugin prova a rinnovarla. Se è già scaduta o revocata, creare un nuovo pairing in WordPress.

## Preparare un design importabile

- Selezionare un frame o componente come radice dell’import.
- Usare nomi chiari per sezioni, varianti e nodi; diventano parte della tracciabilità FEM.
- Tenere immagini e contenuti nella selezione. Gli asset non dichiarati o non coerenti vengono bloccati prima del commit.
- Per responsive, associare frame espliciti desktop/tablet/mobile quando il progetto li possiede. Non dare per scontato che una singola geometria Figma equivalga a tutti i breakpoint del sito.
- Controllare la lista di avvisi per nodi non supportati prima di inviare il design a WordPress.

## Cosa non viene eseguito

FEM non trasferisce né esegue HTML o JavaScript arbitrario proveniente da Figma. Questa scelta previene collisioni di ID/classi, script non verificati e contenuti potenzialmente pericolosi nel tema WordPress. Layout, testo, media e proprietà supportate vengono invece trasformati nei target nativi o nei contenitori FEM.
