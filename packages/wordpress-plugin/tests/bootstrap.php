<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Load the real integration harness before defining any offline substitutes.
$femTestsDir = getenv('FEM_WP_TESTS_DIR');
if ($femTestsDir !== false) {
    $femHarness = $femTestsDir . DIRECTORY_SEPARATOR . 'wp-tests.php';
    if (!is_file($femHarness)) {
        throw new RuntimeException('FEM_WP_TESTS_DIR must contain wp-tests.php.');
    }
    require_once $femHarness;
}

// Minimal read-only WordPress defaults for offline transpiler tests.
// Real WordPress integration runs supply the actual functions.
if (!function_exists('get_option')) {
    function get_option(string $option, mixed $default = false): mixed { return $default; }
}
if (!function_exists('esc_html')) {
    function esc_html(string $text): string { return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

/*
 * Composer is used in WordPress installations. This tiny fallback keeps unit
 * tests runnable before Composer has generated vendor/autoload.php.
 */
spl_autoload_register(
    static function (string $class): void {
        $prefix = 'FEM\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
        $file = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . $relative . '.php';
        if (is_file($file)) {
            require $file;
            return;
        }

        // A few tightly-related value objects deliberately live beside their
        // service/repository; Composer's classmap discovers them in production.
        if (str_starts_with($class, 'FEM\\Security\\')) {
            require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Security' . DIRECTORY_SEPARATOR . 'PairingService.php';
        } elseif (str_starts_with($class, 'FEM\\Infrastructure\\')) {
            require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . 'AssetStore.php';
            require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . 'Repositories.php';
        }
    }
);
