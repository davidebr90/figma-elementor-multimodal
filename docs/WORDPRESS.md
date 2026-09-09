# Configurazione WordPress

## Lingua dell’amministrazione

La pagina **Strumenti → FEM Pairing** usa il text domain `figma-elementor-multimodal` e segue la lingua impostata nell’amministrazione WordPress. L’inglese resta la lingua sorgente; il catalogo italiano viene completato insieme alle restanti superfici amministrative nella linea beta 2.0.

## Requisiti e compatibilità

| Componente | Requisito |
| --- | --- |
| WordPress | 7.0 o successivo |
| PHP | 8.3 o successivo |
| Elementor | 3.20 o successivo, solo per output Elementor |
| Permesso | `manage_fem_imports` (assegnato agli amministratori all’attivazione) |

Il plugin registra il blocco dinamico **FEM Scene** anche quando Elementor non è attivo. La destinazione Elementor resta invece disabilitata se Elementor non soddisfa il requisito.

## Pagina FEM Pairing

La pagina amministrativa è in **Strumenti → FEM Pairing**. Mostra URL del sito, Pairing ID e codice con pulsanti di copia; genera valori una sola volta, così un codice non può essere recuperato involontariamente da una schermata successiva.

La durata selezionabile governa il bearer Figma:

- **8 ore**: scelta raccomandata per un normale lavoro di design;
- **7 giorni**: solo su una workstation sotto controllo dell’amministratore.

Il codice iniziale resta valido dieci minuti, è monouso e si blocca dopo cinque tentativi non validi. Rigenerare sempre il pairing per una nuova installazione o dopo un sospetto di esposizione.

## Dati creati

Le tabelle private hanno il prefisso WordPress e il suffisso `fem_`:

- pairing e credential: hash HMAC, scope, scadenza e stato di revoca;
- audit: eventi tecnici conservati al massimo trenta giorni;
- design e snapshot: contratto FEM verificato con hash di integrità;
- import: stato temporaneo di staging, eliminato alla scadenza;
- idempotency: risultato di commit ripetuti, eliminato alla scadenza.

Il plugin non inserisce un token nel permalink, nell’URL del sito o nel repository. Una credenziale viene inviata nell’header `Authorization` dal plugin Figma soltanto dopo il pairing.

## Target Elementor

FEM genera elementi Elementor nativi per i widget riconosciuti e aggiunge classi FEM stabili, come `fem-design-*`, `fem-node-*` e `fem-role-*`. Queste classi evitano collisioni con le classi del tema e consentono di rintracciare l’origine del nodo; non vanno riutilizzate manualmente come selettori generici del sito.

Aprire sempre la pagina in Elementor dopo l’import. Alcuni nodi Figma complessi possono essere resi come contenitori o richiedere una correzione manuale, indicata dagli avvisi dell’import.

## Target Gutenberg

Il blocco dinamico `fem/scene` protegge l’HTML del contenuto importato: l’editor non riceve JavaScript arbitrario proveniente da Figma. Per immagini e gruppi vengono usati blocchi nativi compatibili quando disponibili. Il pannello del blocco permette le normali impostazioni WordPress supportate dal contratto.

## Aggiornamento e rollback

1. Fare backup di database e file `wp-content/uploads`.
2. Provare l’aggiornamento su staging.
3. Aggiornare il plugin e aprire una pagina FEM esistente.
4. Rinnovare il pairing se la credenziale è scaduta o è stata revocata.
5. Se necessario, ripristinare lo ZIP precedente e il backup coerente del database.

La beta non offre ancora un rollback automatico delle pagine generate. Evitare l’opzione di sostituzione sulla pagina di produzione finché non è stata validata su una copia di test.
