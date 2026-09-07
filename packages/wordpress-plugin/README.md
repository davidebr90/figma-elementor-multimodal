# FEM WordPress plugin

WordPress package for the Figma Elementor Multimodal beta. It receives a verified FEM design contract and renders recognized content as Elementor elements or the dynamic Gutenberg `FEM Scene` block.

Requirements: WordPress 7.0+, PHP 8.3+, and Elementor 3.20+ for the Elementor target.

## Install from source

```powershell
composer install --no-dev --prefer-dist --optimize-autoloader
```

Create a plugin ZIP that contains this directory and its runtime `vendor/` directory, then install it from **Plugins → Add New → Upload Plugin**.

For a detailed setup guide, see [WordPress configuration](../../docs/en/WORDPRESS.md) and [Italian documentation](../../docs/WORDPRESS.md).

## Validation

```powershell
composer install
composer test
composer stan -- --memory-limit=512M
composer lint
```

The optional WordPress/MySQL migration test requires a WordPress test harness. Unit SQL checks alone are not a live `dbDelta` certification.
