# FEM — sistema visivo ufficiale

Questo documento formalizza la direzione visiva approvata per **Figma Elementor Multimodal**. Le immagini di riferimento sono versionate in [`assets/brand`](../../assets/brand/).

## Idea centrale

Il simbolo rappresenta lo stesso contenuto attraverso tre livelli collegati:

1. design e interfaccia;
2. componente, blocco e page builder;
3. codice, struttura dati e serializzazione.

Le frecce laterali comunicano un flusso di trasformazione bidirezionale. Nella versione 2.0 il prodotto implementa prima il percorso Figma → Elementor, ma il visual system deve restare compatibile con l’evoluzione futura Elementor → Figma.

## Palette

| Token | Valore | Uso |
|---|---|---|
| `--fem-orange` | `#FF5A3D` | design, input visuale |
| `--fem-magenta` | `#D50072` | Elementor, componenti, trasformazione |
| `--fem-blue` | `#2F6BFF` | import, collegamento, trasferimento |
| `--fem-navy` | `#172B44` | codice, struttura, affidabilità |
| `--fem-gray` | `#6B7688` | Multimodal e informazioni secondarie |
| `--fem-white` | `#FFFFFF` | separatori e contrasto |

I colori sono piatti: non introdurre gradienti nel logo, nelle icone o nell’interfaccia core senza una decisione esplicita di brand.

## Tipografia

- `Montserrat ExtraBold` / 800 per “Figma Elementor”.
- `Montserrat Medium` / 500 per “Multimodal”.
- fallback UI: `Arial, sans-serif`.
- mantenere “Multimodal” più leggero, più piccolo e con tracking maggiore.

## Varianti e gerarchia

- logo primario: simbolo sopra il logotipo completo;
- icona: tre tavolette + frecce, per favicon, toolbar e spazi ridotti;
- wordmark: testo senza simbolo, per contesti già identificati;
- gerarchia: simbolo → Figma Elementor → Multimodal.

Non appiattire il simbolo in un generico refresh, non usare il marchio ufficiale Figma o Elementor come elemento grafico e non rimuovere i bordi bianchi curvi, che rappresentano la modularità.

## Uso nel prodotto

Il logo primario deve essere usato nella pagina amministrativa FEM, nella schermata di pairing e nelle documentazioni. L’icona deve essere usata per il gruppo Elementor e per gli stati di importazione. I token cromatici devono essere riutilizzati anche nei messaggi di stato:

- blu: importazione o collegamento in corso;
- magenta: componente Elementor pronto;
- arancio: sorgente design o attenzione visuale;
- navy: codice, diagnostica e struttura;
- grigio: metadati e contenuti secondari.

Le immagini sono riferimenti visuali ufficiali; per la distribuzione del plugin sarà preferibile aggiungere in seguito asset SVG vettoriali ottimizzati e favicon derivati senza alterare proporzioni e significato.
