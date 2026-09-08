<?php

declare(strict_types=1);

$wordpressTests = getenv('WP_TESTS_DIR');
if ($wordpressTests === false || !is_dir($wordpressTests)) {
    throw new RuntimeException('WP_TESTS_DIR must point to the WordPress test library.');
}

require_once $wordpressTests . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function (): void {
    require dirname(__DIR__) . '/figma-elementor-multimodal.php';
});

require_once $wordpressTests . '/includes/bootstrap.php';
