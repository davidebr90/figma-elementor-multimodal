# Sviluppo e verifiche

Questa guida consente di riprodurre le verifiche senza usare dati reali.

## Plugin WordPress

```powershell
cd packages/wordpress-plugin
composer install
php vendor/bin/phpunit -c phpunit.xml.dist
php vendor/bin/phpstan analyse --configuration=phpstan.neon --memory-limit=512M
php vendor/bin/phpcs --standard=phpcs.xml.dist
```

Per creare un pacchetto di distribuzione dal sorgente, installare le dipendenze runtime senza quelle di sviluppo e includere `vendor/` nello ZIP del plugin:

```powershell
composer install --no-dev --prefer-dist --optimize-autoloader
```

In PowerShell è disponibile anche lo script di packaging riproducibile:

```powershell
.\tools\package-release.ps1
```

Lo script accetta Composer globale, `tools/composer.phar` oppure il runtime locale `.runtime/composer.phar`.

Genera gli ZIP in `dist/`, directory esclusa dal controllo di versione. Il pacchetto Figma è per il caricamento manuale in sviluppo; per un sito reale resta consigliato il manifest ristretto generato dal wizard.

Non includere file `.env`, log, dump database, directory `vendor` di sviluppo o credenziali nel repository.

## Plugin Figma

```powershell
cd packages/figma-plugin
node --test tests/workflow.test.cjs
node --check src/code.js
```

I test simulano il confine Figma/rete: non sostituiscono un controllo nel client Figma con un file reale.

## Installer

```powershell
cd apps/installer
npm ci
npm test
npm run build
cargo test --locked --manifest-path src-tauri/Cargo.toml
```

## Controllo locale isolato

`compose.fem-test.yaml` crea un ambiente WordPress/MariaDB locale per test manuali. Le password elencate nel file sono esclusivamente valori usa-e-getta del container locale; non riusarli altrove.

```powershell
docker compose -f compose.fem-test.yaml up -d
```

Al termine arrestare l’ambiente con:

```powershell
docker compose -f compose.fem-test.yaml down
```
