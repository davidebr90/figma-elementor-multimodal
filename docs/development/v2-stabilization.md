# Stabilizzazione FEM 2.0

Questo documento distingue le funzioni già presenti dalle verifiche ancora necessarie per dichiarare FEM 2.0 una release stabile. Ogni voce richiede una prova ripetibile prima di cambiare stato.

| Area | Stato beta | Criterio per la stabile |
| --- | --- | --- |
| Contratto Figma → WordPress | Schema 1.1, capability negotiation e validazione disponibili | Fixture compatibili tra client e server, con migrazione verificata |
| Pairing e credenziali | Scope, scadenza, rinnovo e revoca disponibili | Test di rete interrotta, rinnovo durante import e revoca concorrente |
| Staging e commit | Asset manifest, scadenza, integrità e idempotenza disponibili | Nessun contenuto parziale dopo errori, richieste duplicate o retry |
| Elementor e Gutenberg | Import nativo e ID con namespace disponibili | Test su WordPress, Elementor e Gutenberg reali con append, replace e duplicazione |
| Responsive | Layout, paint, bordi, tipografia e geometria delle immagini desktop/tablet/mobile disponibili; il client normalizza anche i metadati `fem-responsive` legacy in gruppi `layout`/`style`/`text` | Confronti visivi su breakpoint e larghezze intermedie per i casi dichiarati supportati |
| Contenuti complessi | Nessuna conversione universale di prototipi o JavaScript | Catalogo di interazioni dichiarative accessibili; modalità isolata solo se validata |
| Installer | Windows compilato; pipeline macOS/Linux disponibile | Build native, installazione pulita, aggiornamento, firma e test su piattaforme target |
| Runtime Docker Windows | Ambiente Compose disponibile, ma host locale instabile; migrazione WordPress reale coperta in CI Linux | Più cicli di avvio/arresto riusciti; estendere il runtime CI a pairing e import |

## Regole per le modifiche

1. Ogni nuovo comportamento del plugin deve avere un test che fallisce prima della modifica.
2. Gli endpoint REST trasformano gli input non validi in risposte 4xx strutturate: nessuna eccezione di validazione deve arrivare al client come errore 500.
3. I valori importati devono mantenere origine, identificatore nodo e proprietà per consentire futuri conflitti e reimport controllati.
4. Le classi CSS sono nomi derivati e con namespace; l'identità primaria usa gli identificatori FEM persistenti.
5. Il flusso inverso WordPress → Figma resta fuori dalla 2.0: vengono preparati solo dati di provenienza e binding necessari a progettarlo in seguito.

## Sequenza di rilascio

1. Consolidare CI e test di confine REST.
2. Aggiungere test runtime riproducibili su Linux e ripetere quelli locali quando Docker Desktop è disponibile.
3. Ampliare il mapping responsive con fixture visive e avvisi di approssimazione.
4. Aggiungere UX di conflitto e ripristino per proprietà personalizzate in Elementor e Gutenberg.
5. Validare installazione e aggiornamento su Windows, macOS e Linux.
6. Aggiornare, nello stesso commit di ogni cambiamento funzionale, la guida utente interessata e la sezione `Unreleased` del changelog. Checklist, tag e release vengono aggiornati solo dopo che le prove richieste sono verdi.
