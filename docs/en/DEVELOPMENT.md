# Development and validation

This guide reproduces checks without real data.

## WordPress plugin

```powershell
cd packages/wordpress-plugin
composer install
php vendor/bin/phpunit -c phpunit.xml.dist
php vendor/bin/phpstan analyse --configuration=phpstan.neon --memory-limit=512M
php vendor/bin/phpcs --standard=phpcs.xml.dist
```

To create a distribution package from source, install runtime dependencies without development packages and include `vendor/` in the plugin ZIP:

```powershell
composer install --no-dev --prefer-dist --optimize-autoloader
```

A reproducible packaging script is also available in PowerShell:

```powershell
.\tools\package-release.ps1
```

It creates ZIP files in `dist/`, which is excluded from version control. The Figma package is for manual development loading; for a real website the wizard-generated restricted manifest remains the recommended route.

Do not include `.env` files, logs, database dumps, development `vendor` folders, or credentials in the repository.

## Figma plugin

```powershell
cd packages/figma-plugin
node --test tests/workflow.test.cjs
node --check src/code.js
```

The tests simulate the Figma/network boundary; they do not replace a check in the real Figma client with an actual file.

## Installer

```powershell
cd apps/installer
npm ci
npm test
npm run build
cargo test --locked --manifest-path src-tauri/Cargo.toml
```

## Isolated local check

`compose.fem-test.yaml` starts a local WordPress/MariaDB environment for manual testing. Its passwords are disposable local-container values only; never reuse them elsewhere.

```powershell
docker compose -f compose.fem-test.yaml up -d
```

Stop the environment afterwards with:

```powershell
docker compose -f compose.fem-test.yaml down
```
