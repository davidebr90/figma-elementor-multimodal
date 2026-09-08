<?php

declare(strict_types=1);

namespace FEM\Tests\Integration;

use FEM\Plugin;
use FEM\Infrastructure\Database;
use PHPUnit\Framework\TestCase;

final class WordPressMigrationTest extends TestCase
{
    public function testMigrationIsIdempotentAgainstARealWordPressDatabase(): void
    {
        $testsDir = getenv('FEM_WP_TESTS_DIR');
        if ($testsDir !== false && !function_exists('dbDelta') && is_file($testsDir . DIRECTORY_SEPARATOR . 'wp-tests.php')) {
            require_once $testsDir . DIRECTORY_SEPARATOR . 'wp-tests.php';
        }
        if ($testsDir === false || !function_exists('dbDelta') || !isset($GLOBALS['wpdb'])) {
            self::markTestSkipped('Requires a WordPress integration harness and MySQL; SQL-string tests are not a substitute.');
        }

        $database = new Database($GLOBALS['wpdb']);
        $database->migrate();
        $database->migrate();

        self::assertSame((string) Database::SCHEMA_VERSION, get_option('fem_schema_version'));
    }

    public function testPairingExchangeAndImportStagingWorkThroughTheRealRestServer(): void
    {
        $this->requireWordPressHarness();
        Plugin::activate();
        Plugin::boot();
        do_action('rest_api_init');

        $userId = wp_insert_user([
            'user_login' => 'fem-integration-' . wp_generate_uuid4(),
            'user_pass' => wp_generate_password(24, true, true),
            'user_email' => 'fem-integration-' . wp_generate_uuid4() . '@example.test',
            'role' => 'administrator',
        ]);
        self::assertIsInt($userId);
        wp_set_current_user($userId);
        $pairingRequest = new \WP_REST_Request('POST', '/figma-elementor-multimodal/v1/pairings');
        $pairingResponse = rest_get_server()->dispatch($pairingRequest);
        self::assertSame(200, $pairingResponse->get_status());
        $pairing = $pairingResponse->get_data()['data'];

        $exchangeRequest = new \WP_REST_Request('POST', '/figma-elementor-multimodal/v1/pairings/exchange');
        $exchangeRequest->set_header('Content-Type', 'application/json');
        $exchangeRequest->set_body((string) wp_json_encode([
            'pairingId' => $pairing['pairingId'],
            'code' => $pairing['code'],
            'deviceId' => 'wordpress-integration-device',
            'challenge' => 'wordpress-integration-challenge',
            'requestedScopes' => ['import:write'],
        ]));
        $exchangeResponse = rest_get_server()->dispatch($exchangeRequest);
        self::assertSame(200, $exchangeResponse->get_status());
        $credential = $exchangeResponse->get_data()['data']['deviceCredential'];

        /** @var array<string,mixed> $document */
        $document = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/valid-minimal.json'), true, 512, JSON_THROW_ON_ERROR);
        $stageRequest = new \WP_REST_Request('POST', '/figma-elementor-multimodal/v1/imports');
        $stageRequest->set_header('Content-Type', 'application/json');
        $stageRequest->set_header('Authorization', 'Bearer ' . $credential);
        $stageRequest->set_body((string) wp_json_encode(['manifest' => ['assets' => []], 'document' => $document]));
        $stageResponse = rest_get_server()->dispatch($stageRequest);

        self::assertSame(200, $stageResponse->get_status());
        self::assertSame('commit', $stageResponse->get_data()['data']['next']);
        $importId = $stageResponse->get_data()['data']['importId'];
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $importId);

        $commitRequest = new \WP_REST_Request('POST', '/figma-elementor-multimodal/v1/imports/' . $importId . '/commit');
        $commitRequest->set_header('Authorization', 'Bearer ' . $credential);
        $commitRequest->set_header('Idempotency-Key', 'wordpress-integration-commit');
        $commitRequest->set_header('If-Match', '"1"');
        $commitResponse = rest_get_server()->dispatch($commitRequest);

        self::assertSame(200, $commitResponse->get_status());
        self::assertSame('1', $commitResponse->get_data()['data']['revision']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $commitResponse->get_data()['data']['snapshotId']);
    }

    private function requireWordPressHarness(): void
    {
        $testsDir = getenv('FEM_WP_TESTS_DIR');
        if ($testsDir !== false && !function_exists('dbDelta') && is_file($testsDir . DIRECTORY_SEPARATOR . 'wp-tests.php')) {
            require_once $testsDir . DIRECTORY_SEPARATOR . 'wp-tests.php';
        }
        if ($testsDir === false || !function_exists('dbDelta') || !isset($GLOBALS['wpdb'])) {
            self::markTestSkipped('Requires a WordPress integration harness and MySQL.');
        }
    }
}
