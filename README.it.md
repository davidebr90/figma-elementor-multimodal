# Figma Elementor Multimodal

![Figma Elementor Multimodal](assets/brand/fem-logo-primary.png)

Figma Elementor Multimodal (FEM) è un ponte open source e self-hosted che porta una selezione Figma in WordPress, come elementi Elementor modificabili oppure come blocchi nativi Gutenberg.

**Stato attuale: beta 0.1.0.** Il progetto è pensato per ambienti di test controllati. Ogni pagina importata va verificata prima della pubblicazione su un sito in produzione.

🇬🇧 [English](README.md) · [Documentazione italiana](docs/README.md) · [English documentation](docs/en/README.md)

## Cosa fa

- Importa in WordPress un frame o componente selezionato in Figma.
- Traduce le strutture riconosciute in elementi Elementor nativi o nel blocco Gutenberg `FEM Scene`.
- Mantiene una mappatura deterministica tra nodi/classi FEM per tracciabilità e futuro lavoro di sincronizzazione inversa.
- Importa le immagini con un flusso a fasi e verifica dell’integrità.
- Collega Figma a uno specifico sito WordPress tramite pairing temporaneo.
- Include **FEM Setup**, un wizard desktop che prepara un pacchetto Figma limitato al singolo sito.

In questa beta FEM è volutamente **unidirezionale**: Figma → WordPress. Non riscrive il file Figma a partire da WordPress.

## Requisiti

- WordPress 7.0 o successivo e PHP 8.3 o successivo.
- Elementor 3.20 o successivo quando viene scelto Elementor come destinazione.
- Una licenza Figma valida e attiva, Figma Desktop e i plugin di sviluppo abilitati.
- HTTPS per un sito reale. HTTP è ammesso solo per `localhost`, `127.0.0.1` o `[::1]` con modalità locale esplicitamente attiva.

Su GNU/Linux, FEM supporta il flusso con plugin di sviluppo manuale tramite [Figma-Linux](https://github.com/Figma-Linux/figma-linux). La possibilità di caricare il manifest va verificata nella build Figma-Linux installata.

## Avvio rapido

1. Installa e attiva il pacchetto WordPress in [`packages/wordpress-plugin`](packages/wordpress-plugin).
2. In WordPress apri **Strumenti → FEM Pairing**, scegli la durata della credenziale e genera un codice.
3. Compila o avvia il wizard in [`apps/installer`](apps/installer); inserisci l’URL del sito, verificalo e prepara il pacchetto Figma.
4. In Figma Desktop scegli **Plugins → Development → Import plugin from manifest…** e seleziona il manifest creato da FEM Setup.
5. Apri FEM, incolla Pairing ID e codice, seleziona un frame o componente, scegli Elementor oppure Gutenberg e importa.
6. Controlla la pagina risultante in desktop, tablet e mobile prima di pubblicarla.

Guide complete: [configurazione WordPress](docs/WORDPRESS.md), [configurazione Figma](docs/FIGMA.md), [stato installer](docs/INSTALLER.md) e [limiti noti](docs/LIMITI.md).

## Struttura del repository

```text
packages/
  figma-plugin/       plugin di sviluppo Figma
  wordpress-plugin/   integrazione WordPress, Elementor e Gutenberg
apps/installer/       wizard desktop FEM Setup (Tauri)
assets/brand/         asset e token visivi FEM approvati
docs/                 documentazione italiana
docs/en/              documentazione inglese
tools/                strumenti di verifica e release riproducibile
```

## Sicurezza e privacy

Nel repository non sono presenti URL, codici di pairing, bearer credential, API key o chiavi private. FEM usa credenziali temporanee e limitate al sito; il server conserva hash HMAC-SHA-256 delle credenziali, non i bearer in chiaro. Prima di usare FEM oltre l’ambiente di test, leggere la [policy di sicurezza](SECURITY.md).

## Licenza

Figma Elementor Multimodal è distribuito con licenza [GNU Affero General Public License v3.0](LICENSE).

I nomi Figma, WordPress, Gutenberg ed Elementor appartengono ai rispettivi titolari. FEM è un progetto indipendente e non è affiliato né approvato da tali soggetti.

## Autore

Davide Pica — [@davidebr90](https://github.com/davidebr90)
