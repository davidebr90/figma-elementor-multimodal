# Sicurezza e privacy

## Confini di sicurezza

FEM tratta il sito WordPress come proprietario del pairing. Il plugin Figma non contiene un endpoint o un token preimpostato: FEM Setup genera localmente un manifest limitato all’origine scelta.

- il codice di pairing dura 10 minuti, è monouso e supporta al massimo 5 tentativi non validi;
- la credenziale Figma dura 8 ore o 7 giorni, secondo la scelta dell’amministratore;
- sul server viene salvato un hash HMAC-SHA-256 della credenziale, non il bearer in chiaro;
- gli import temporanei scadono dopo un’ora;
- i commit accettano una chiave di idempotenza e gli asset devono corrispondere esattamente al manifesto e all’hash del documento;
- gli audit tecnici e gli stati scaduti vengono eliminati automaticamente.

## CORS e autenticazione

Il canvas dei plugin Figma usa un’origine isolata, quindi le rotte FEM rispondono con una policy CORS compatibile con il bearer. Le rotte FEM non accettano cookie WordPress come autenticazione di import e non abilitano credenziali CORS. L’accesso richiede l’header bearer temporaneo e lo scope appropriato.

Non aggiungere token nell’URL del sito e non mettere il codice di pairing in screenshot, issue o repository.

## Checklist prima di un ambiente reale

1. Usare HTTPS con certificato valido.
2. Limitare l’accesso amministrativo WordPress e aggiornare core, tema, plugin ed Elementor.
3. Tenere PHP almeno alla versione richiesta e disabilitare il debug pubblico.
4. Generare pairing da un browser fidato e scegliere 7 giorni solo su una workstation affidabile.
5. Rinnovare o rigenerare il pairing in caso di dispositivo perso, persona non più autorizzata o dubbio di esposizione.
6. Fare backup di database e media prima del primo import o di un aggiornamento.
7. Testare prima su staging.

## Segnalazioni

Per una vulnerabilità usare il canale privato di segnalazione GitHub del repository, quando disponibile. Non inviare password, token, codici di pairing, chiavi private, backup o dati reali degli utenti.
