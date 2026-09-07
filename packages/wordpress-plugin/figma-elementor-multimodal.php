<?php
/**
 * Plugin Name: Figma Elementor Multimodal
 * Description: Secure Figma-to-WordPress integration for Elementor and Gutenberg.
 * Version: 0.1.0
 * Requires at least: 7.0
 * Requires PHP: 8.3
 * Text Domain: figma-elementor-multimodal
 */


declare(strict_types=1);

if (!defined('ABSPATH')) {
    return;
}

/* Keep this file parseable and actionable on PHP 7; PHP 8-only classes load later. */
if (version_compare(PHP_VERSION, '8.3.0', '<')) {
    if (function_exists('add_action')) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>Figma Elementor Multimodal requires PHP 8.3 or newer.</p></div>';
        });
    }
    return;
}

$femAutoloader = __DIR__ . '/vendor/autoload.php';
if (is_file($femAutoloader)) {
    require_once $femAutoloader;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'FEM\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $path = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require $path;
            return;
        }
        if (strncmp($class, 'FEM\\Security\\', 13) === 0) {
            require_once __DIR__ . '/src/Security/PairingService.php';
        } elseif (strncmp($class, 'FEM\\Infrastructure\\', 19) === 0) {
            require_once __DIR__ . '/src/Infrastructure/AssetStore.php';
            require_once __DIR__ . '/src/Infrastructure/Repositories.php';
        }
    });
}

register_activation_hook(__FILE__, ['FEM\\Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['FEM\\Plugin', 'deactivate']);
add_action('plugins_loaded', ['FEM\\Plugin', 'boot']);
