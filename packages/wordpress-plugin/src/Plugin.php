<?php

declare(strict_types=1);

namespace FEM;

use DateInterval;
use FEM\Api\RequestLimits;
use FEM\Api\ResponseFactory;
use FEM\Api\RestController;
use FEM\Application\ImportService;
use FEM\Application\WpImportStore;
use FEM\Infrastructure\Database;
use FEM\Infrastructure\SchemaValidator;
use FEM\Infrastructure\WpAssetStore;
use FEM\Infrastructure\WpAuditRepository;
use FEM\Infrastructure\WpCredentialRepository;
use FEM\Infrastructure\WpDesignRepository;
use FEM\Infrastructure\WpIdempotencyRepository;
use FEM\Infrastructure\WpPairingRepository;
use FEM\Infrastructure\WpSnapshotRepository;
use FEM\Security\PairingService;
use FEM\Security\SystemClock;
use FEM\Security\SystemRandomSource;

final class Plugin
{
    public const MINIMUM_PHP = '8.3.0';
    public const MINIMUM_WORDPRESS = '7.0';
    public const MINIMUM_ELEMENTOR = '3.20.0';
    public const TEXT_DOMAIN = 'figma-elementor-multimodal';

    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted || !self::compatible()) {
            return;
        }
        self::$booted = true;
        self::ensureSchema();
        self::scheduleAuditPrune();
        if (function_exists('add_action')) {
            add_action('fem_prune_audit', [self::class, 'pruneAudit']);
            add_action('rest_api_init', [self::class, 'registerRestRoutes']);
            add_action('admin_menu', [self::class, 'registerAdminMenu']);
            add_action('init', [self::class, 'registerBlocks']);
            add_action('init', [self::class, 'loadTranslations']);
        }

        if (!self::elementorLoaded()) {
            self::notice('Figma Elementor Multimodal requires Elementor ' . self::MINIMUM_ELEMENTOR . ' or newer; imports build native Elementor elements, so it is required.');
        }
    }

    public static function activate(): void
    {
        if (!self::compatible() || !isset($GLOBALS['wpdb']) || !is_object($GLOBALS['wpdb'])) {
            return;
        }
        (new Database($GLOBALS['wpdb']))->migrate();
        if (function_exists('get_role')) {
            $administrator = get_role('administrator');
            if ($administrator !== null) {
                $administrator->add_cap('manage_fem_imports');
            }
        }
        self::scheduleAuditPrune();
    }

    public static function deactivate(): void
    {
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook('fem_prune_audit');
        }
    }

    public static function pruneAudit(): void
    {
        if (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb'])) {
            $database = new Database($GLOBALS['wpdb']);
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $database->pruneAudit($now);
            $database->pruneExpiredState($now);
        }
    }

    public static function registerRestRoutes(): void
    {
        if (!isset($GLOBALS['wpdb']) || !is_object($GLOBALS['wpdb'])) {
            return;
        }
        $pairing = self::pairingService();
        if ($pairing === null) {
            return;
        }
        $database = new Database($GLOBALS['wpdb']);
        $imports = new ImportService(new SchemaValidator(), new WpAssetStore(), new WpImportStore($database), new WpDesignRepository($database), new WpSnapshotRepository($database), new WpIdempotencyRepository($database), new SystemClock(), new SystemRandomSource());
        (new RestController($pairing, $imports, new RequestLimits(), new ResponseFactory()))->register();
    }

    private static function ensureSchema(): void
    {
        if (!isset($GLOBALS['wpdb']) || !is_object($GLOBALS['wpdb']) || !function_exists('get_option')) {
            return;
        }
        if ((string) get_option('fem_schema_version', '') === (string) Database::SCHEMA_VERSION) {
            return;
        }
        (new Database($GLOBALS['wpdb']))->migrate();
    }

    public static function registerBlocks(): void
    {
        if (!function_exists('register_block_type')) {
            return;
        }
        $block = __DIR__ . '/../blocks/fem-scene';
        if (is_file($block . '/block.json')) {
            register_block_type($block);
        }
    }

    public static function loadTranslations(): void
    {
        if (function_exists('load_plugin_textdomain')) {
            load_plugin_textdomain(self::TEXT_DOMAIN, false, 'figma-elementor-multimodal/languages');
        }
    }

    public static function registerAdminMenu(): void
    {
        if (function_exists('add_management_page')) {
            add_management_page(self::translate('FEM Pairing'), self::translate('FEM Pairing'), 'manage_fem_imports', 'fem-pairing', [self::class, 'adminPage']);
        }
    }

    public static function adminPage(): void
    {
        if (!function_exists('current_user_can') || !current_user_can('manage_fem_imports')) {
            return;
        }
        $pairing = null;
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && function_exists('check_admin_referer')) {
            check_admin_referer('fem_create_pairing');
            $requestedTtl = sanitize_key((string) ($_POST['fem_credential_ttl'] ?? '8h'));
            if (in_array($requestedTtl, ['8h', '7d'], true) && function_exists('update_option')) {
                update_option('fem_credential_ttl', $requestedTtl, false);
            }
            $service = self::pairingService();
            if ($service !== null) {
                $pairing = $service->create((int) get_current_user_id());
            }
        }
        $ttl = self::credentialTtlKey();
        echo '<div class="wrap fem-pairing-page"><h1>' . esc_html(self::translate('FEM Pairing')) . '</h1><p>' . esc_html(self::translate('Use these values in the FEM plugin inside Figma. The pairing code expires in ten minutes.')) . '</p>';
        if ($pairing !== null) {
            echo '<div class="notice notice-success"><p><strong>Pairing ready.</strong> Copy the values below into Figma. The code will not be shown again.</p></div><div class="fem-pairing-card">';
            echo self::pairingField('WordPress site URL', 'fem-site-url', self::siteUrl());
            echo self::pairingField('Pairing ID', 'fem-pairing-id', $pairing->pairingId);
            echo self::pairingField('Pairing code', 'fem-pairing-code', $pairing->code);
            echo '<p class="description">Pairing code expires at <strong>' . esc_html($pairing->expiresAt->format('Y-m-d H:i:s T')) . '</strong>. The connected Figma credential is valid for up to ' . esc_html($ttl === '7d' ? '7 days' : '8 hours') . ' and can be renewed automatically before expiry.</p>';
            echo '</div>';
            // The values are shown once, so copying must not depend on manual selection.
            echo '<script>document.querySelectorAll(".fem-copy").forEach(function(button){button.addEventListener("click",function(){'
                . 'var input=document.getElementById(button.dataset.target);input.select();input.setSelectionRange(0,input.value.length);'
                . 'var original=button.textContent;var done=function(){button.textContent="Copied";button.classList.add("is-copied");button.setAttribute("aria-label","Copied "+input.value);setTimeout(function(){button.textContent=original;button.classList.remove("is-copied");button.setAttribute("aria-label","Copy "+button.dataset.label);},1600);};'
                . 'var failed=function(){button.textContent="Select & copy";setTimeout(function(){button.textContent=original;},1800);};'
                . 'if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(input.value).then(done,failed);}else{try{document.execCommand("copy");done();}catch(error){failed();}}});});</script>';
            echo '<style>.fem-pairing-card{max-width:760px;background:#fff;border:1px solid #dcdcde;border-radius:16px;padding:10px 22px 22px;box-shadow:0 8px 24px rgba(23,43,68,.08)}.fem-pairing-field{display:flex;gap:12px;align-items:end;padding-top:16px}.fem-pairing-field label{display:block;flex:1;font-weight:600;color:#172b44}.fem-pairing-field input{display:block;width:100%;margin-top:7px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;border-color:#cbd5e1;border-radius:8px;background:#f8fbff}.fem-pairing-field input:focus{border-color:#2f6bff;box-shadow:0 0 0 3px rgba(47,107,255,.14)}.fem-copy{min-width:84px;text-align:center;border-radius:8px}.fem-copy.is-copied{background:#d50072;border-color:#d50072;color:#fff}.fem-copy:focus-visible{outline:3px solid #72aee6;outline-offset:2px}.fem-pairing-page select{min-width:260px;border-radius:8px;border-color:#cbd5e1}.fem-pairing-page .notice-success{border-left-color:#d50072}</style>';
        }
        echo '<form method="post">';
        if (function_exists('wp_nonce_field')) {
            wp_nonce_field('fem_create_pairing');
        }
        echo '<p><label for="fem-credential-ttl"><strong>Figma connection duration</strong></label><br><select id="fem-credential-ttl" name="fem_credential_ttl"><option value="8h"' . selected($ttl, '8h', false) . '>8 hours (recommended)</option><option value="7d"' . selected($ttl, '7d', false) . '>7 days (persistent workstation)</option></select></p><p class="description">Both modes expire and can be revoked. Use 7 days only on a trusted workstation.</p>';
        echo '<p><button class="button button-primary" type="submit">Generate pairing code</button></p></form></div>';
    }

    private static function siteUrl(): string
    {
        $home = function_exists('home_url') ? home_url('/') : '';
        return rtrim((string) $home, '/');
    }

    private static function pairingField(string $label, string $id, string $value): string
    {
        return '<div class="fem-pairing-field"><label>' . esc_html($label) . ' <input id="' . esc_attr($id) . '" class="regular-text" readonly value="' . esc_attr($value) . '"></label> '
            . '<button type="button" class="button fem-copy" data-target="' . esc_attr($id) . '" data-label="' . esc_attr($label) . '" aria-label="' . esc_attr(self::translate('Copy') . ' ' . $label) . '">' . esc_html(self::translate('Copy')) . '</button></div>';
    }

    private static function translate(string $text): string
    {
        return function_exists('__') ? (string) __($text, self::TEXT_DOMAIN) : $text;
    }

    private static function scheduleAuditPrune(): void
    {
        if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_event') && !wp_next_scheduled('fem_prune_audit')) {
            wp_schedule_event(time() + 3600, 'daily', 'fem_prune_audit');
        }
    }

    private static function pairingService(): ?PairingService
    {
        if (!isset($GLOBALS['wpdb']) || !is_object($GLOBALS['wpdb'])) {
            return null;
        }
        $database = new Database($GLOBALS['wpdb']);
        $hmacKey = hash('sha256', (string) (defined('AUTH_KEY') ? AUTH_KEY : 'fem-local-key') . '|' . (string) (defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : 'fem-secure-key'));
        $ttl = self::credentialTtlKey() === '7d' ? new DateInterval('P7D') : new DateInterval('PT8H');
        return new PairingService(new WpPairingRepository($database), new WpCredentialRepository($database), new WpAuditRepository($database), new SystemClock(), new SystemRandomSource(), $hmacKey, $ttl);
    }

    private static function credentialTtlKey(): string
    {
        $value = function_exists('get_option') ? (string) get_option('fem_credential_ttl', '8h') : '8h';
        return in_array($value, ['8h', '7d'], true) ? $value : '8h';
    }

    private static function compatible(): bool
    {
        if (version_compare(PHP_VERSION, self::MINIMUM_PHP, '<')) {
            self::notice('Figma Elementor Multimodal requires PHP ' . self::MINIMUM_PHP . ' or newer.');
            return false;
        }
        global $wp_version;
        if (isset($wp_version) && version_compare((string) $wp_version, self::MINIMUM_WORDPRESS, '<')) {
            self::notice('Figma Elementor Multimodal requires WordPress ' . self::MINIMUM_WORDPRESS . ' or newer.');
            return false;
        }
        return true;
    }

    private static function elementorLoaded(): bool
    {
        if (!function_exists('did_action') || did_action('elementor/loaded') === 0) {
            return false;
        }
        return defined('ELEMENTOR_VERSION') && version_compare((string) ELEMENTOR_VERSION, self::MINIMUM_ELEMENTOR, '>=');
    }

    private static function notice(string $message): void
    {
        if (!function_exists('add_action')) {
            return;
        }
        add_action('admin_notices', static function () use ($message): void {
            if (function_exists('esc_html')) {
                $message = esc_html($message);
            }
            echo '<div class="notice notice-warning"><p>' . $message . '</p></div>';
        });
    }
}
