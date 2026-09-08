# Limiti noti e verifiche obbligatorie

## Beta e direzione del flusso

FEM 2.0 beta (ultima build pubblicata: `v2.0.0-beta.1`) gestisce soltanto Figma → WordPress. Salva metadati di mappatura per rendere possibile un futuro percorso WordPress → Figma, ma non espone ancora un reimport inverso.

## Fedeltà visiva e responsive

L’import traduce proprietà e layout supportati; non promette equivalenza pixel-perfect in ogni combinazione di tema, browser, font e breakpoint. Prima della pubblicazione controllare:

- margini, padding, bordi, ombre e radius;
- font effettivamente disponibili sul sito;
- desktop, tablet e mobile;
- immagini e gallery nel front-end;
- widget Elementor e blocchi Gutenberg dopo un salvataggio dell’editor.

I frame responsive devono essere dichiarati e associati chiaramente in Figma. Se non è disponibile una variante tablet o mobile, FEM importa la base e segnala il contesto: la correzione finale resta una scelta del redattore WordPress.

## Nodi complessi e animazioni

FEM supporta un insieme controllato di contenitori, testo, heading, pulsanti, immagini, gallery/carousel, accordion, icone, divider e spacer. Gli elementi non riconosciuti vengono segnalati; non vengono trasformati in HTML/JavaScript arbitrario.

Animazioni, prototipi Figma, librerie JavaScript di terze parti, iframe e codice custom non sono trasferiti automaticamente. Integrare tali funzioni con componenti WordPress controllati, con una revisione di sicurezza separata.

## Installer

Il wizard genera il pacchetto Figma locale, ma la release stabile con firma Windows, notarizzazione macOS e verifica estesa Linux non è ancora disponibile. Vedere [INSTALLER.md](INSTALLER.md).

## Cosa fare quando un import non riesce

1. Non ritentare con un codice scaduto: generarne uno nuovo.
2. Verificare URL, HTTPS e namespace REST dal wizard.
3. Controllare la selezione Figma e gli avvisi per nodi non supportati.
4. Controllare i log WordPress senza copiarvi credenziali.
5. Riprovarci su una pagina di test dopo aver rinnovato il pairing, se necessario.

Un errore non deve lasciare un import parziale: lo staging ha scadenza, gli asset sono verificati e il commit è idempotente. Resta comunque necessario controllare la pagina di destinazione se è stato richiesto un rendering esplicito.
