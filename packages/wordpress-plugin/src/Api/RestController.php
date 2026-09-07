<?php

declare(strict_types=1);

namespace FEM\Api;

use FEM\Application\ImportService;
use FEM\Security\AuthenticatedDevice;
use FEM\Security\PairingException;
use FEM\Security\PairingService;

final class RestController
{
    private const NAMESPACE = 'figma-elementor-multimodal/v1';

    public function __construct(private readonly PairingService $pairing, private readonly ImportService $imports, private readonly RequestLimits $limits = new RequestLimits(), private readonly ResponseFactory $responses = new ResponseFactory())
    {
    }

    /** @var list<string> Request headers this API requires, beyond the WordPress defaults. */
    private const CORS_HEADERS = ['Idempotency-Key', 'If-Match'];

    /** Post meta holding the design a page renders; protected, so it stays out of the editor UI. */
    public const DESIGN_META = '_fem_design_id';

    private const MAX_PAGES = 200;

    public function register(): void
    {
        if (!function_exists('register_rest_route')) {
            return;
        }
        // Staging, asset upload and commit send Idempotency-Key/If-Match. Without
        // them on the CORS allowlist a browser client (the Figma plugin) fails its
        // preflight and the request never reaches WordPress.
        if (function_exists('add_filter')) {
            add_filter('rest_allowed_cors_headers', static function (array $headers): array {
                return array_values(array_unique(array_merge($headers, self::CORS_HEADERS)));
            });
            // WordPress reflects any Origin and enables cookie credentials by
            // default. This API uses bearer credentials only, while Figma plugin
            // iframes require a wildcard origin; override only the FEM namespace.
            add_filter('rest_pre_serve_request', [$this, 'applyCorsPolicy'], 20, 4);
        }
        $importRoute = self::importRouteDefinition();
        $importRoute['callback'] = [$this, 'stage'];
        $importRoute['permission_callback'] = [$this, 'requireBearer'];
        register_rest_route(self::NAMESPACE, '/pairings', ['methods' => 'POST', 'callback' => [$this, 'createPairing'], 'permission_callback' => [$this, 'requireAdmin']]);
        register_rest_route(self::NAMESPACE, '/pairings/(?P<pairingId>[A-Za-z0-9_-]{20,64})', ['methods' => 'DELETE', 'callback' => [$this, 'deletePairing'], 'permission_callback' => [$this, 'requireAdmin']]);
        register_rest_route(self::NAMESPACE, '/pairings/exchange', ['methods' => 'POST', 'callback' => [$this, 'exchangePairing'], 'permission_callback' => [$this, 'allowExchange']]);
        register_rest_route(self::NAMESPACE, '/pairings/renew', ['methods' => 'POST', 'callback' => [$this, 'renewPairing'], 'permission_callback' => [$this, 'allowExchange']]);
        register_rest_route(self::NAMESPACE, '/imports', $importRoute);
        register_rest_route(self::NAMESPACE, '/imports/(?P<importId>[a-f0-9]{32})/assets/(?P<sha256>[a-f0-9]{64})', ['methods' => 'PUT', 'callback' => [$this, 'asset'], 'permission_callback' => [$this, 'requireAssetBearer'], 'args' => ['importId' => ['required' => true, 'sanitize_callback' => 'sanitize_key'], 'sha256' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field']]]);
        register_rest_route(self::NAMESPACE, '/imports/(?P<importId>[a-f0-9]{32})/commit', ['methods' => 'POST', 'callback' => [$this, 'commit'], 'permission_callback' => [$this, 'requireBearer']]);
        register_rest_route(self::NAMESPACE, '/imports/(?P<importId>[a-f0-9]{32})', ['methods' => 'GET', 'callback' => [$this, 'status'], 'permission_callback' => [$this, 'requireBearer']]);
        register_rest_route(self::NAMESPACE, '/pages', ['methods' => 'GET', 'callback' => [$this, 'pages'], 'permission_callback' => [$this, 'requireBearer']]);
        register_rest_route(self::NAMESPACE, '/pages/(?P<pageId>[0-9]+)/design', ['methods' => 'POST', 'callback' => [$this, 'attachDesign'], 'permission_callback' => [$this, 'requireBearer'], 'args' => ['pageId' => ['required' => true, 'sanitize_callback' => 'absint']]]);
        register_rest_route(self::NAMESPACE, '/design-system', ['methods' => 'POST', 'callback' => [$this, 'syncDesignSystem'], 'permission_callback' => [$this, 'requireBearer']]);
        register_rest_route(self::NAMESPACE, '/pages/(?P<pageId>[0-9]+)/elementor', ['methods' => 'POST', 'callback' => [$this, 'transpileToPage'], 'permission_callback' => [$this, 'requireBearer'], 'args' => ['pageId' => ['required' => true, 'sanitize_callback' => 'absint']]]);
    }

    /** @return array<string,mixed> */
    public static function importRouteDefinition(): array
    {
        // The import payload is the raw JSON request body with manifest and document keys.
        // A required route argument named "body" would make WordPress reject valid JSON
        // requests before the stage callback can decode them.
        return ['methods' => 'POST'];
    }

    public function requireBearer(object $request): bool|object
    {
        return $this->permission($request, 'import:write');
    }

    public function requireAssetBearer(object $request): bool|object
    {
        return $this->permission($request, 'asset:write');
    }

    public function requireAdmin(): bool|object
    {
        if (function_exists('current_user_can') && current_user_can('manage_fem_imports')) {
            return true;
        }
        if (class_exists('WP_Error')) {
            return new \WP_Error('fem_admin_required', 'Administrator capability is required.', ['status' => 403]);
        }
        return false;
    }

    public function allowExchange(): bool
    {
        return true;
    }

    /** @param mixed $result @param mixed $server */
    public function applyCorsPolicy(bool $served, mixed $result, object $request, mixed $server): bool
    {
        $route = method_exists($request, 'get_route') ? (string) $request->get_route() : '';
        $headers = (new CorsPolicy())->headersFor($route);
        if ($headers === [] || headers_sent()) {
            return $served;
        }
        if (function_exists('header_remove')) {
            header_remove('Access-Control-Allow-Credentials');
        }
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value, true);
        }
        return $served;
    }

    private function permission(object $request, string $scope): bool|object
    {
        try {
            $this->device($request, $scope);
            return true;
        } catch (PairingException $exception) {
            $status = $exception->errorCode === 'insufficient_scope' ? 403 : 401;
            if (class_exists('WP_Error')) {
                return new \WP_Error($status === 403 ? 'fem_insufficient_scope' : 'fem_auth_required', 'Authentication failed.', ['status' => $status]);
            }
            return false;
        }
    }

    public function createPairing(object $request): object|array
    {
        $requestId = $this->requestId($request);
        try {
            $userId = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
            $pairing = $this->pairing->create($userId);
            return $this->responses->success(['pairingId' => $pairing->pairingId, 'code' => $pairing->code, 'expiresAt' => $pairing->expiresAt->format(\DateTimeInterface::ATOM), 'allowedScopes' => $pairing->allowedScopes], $requestId);
        } catch (\InvalidArgumentException $exception) {
            return $this->responses->error(422, 'invalid-pairing-request', $exception->getMessage(), $requestId);
        }
    }

    public function exchangePairing(object $request): object|array
    {
        $requestId = $this->requestId($request);
        try {
            $body = $this->limits->decodeJson((string) $request->get_body());
            $scopes = $body['requestedScopes'] ?? [];
            if (!is_array($scopes)) {
                return $this->responses->error(422, 'invalid-scope', 'requestedScopes must be an array.', $requestId);
            }
            $credential = $this->pairing->exchange((string) ($body['pairingId'] ?? ''), (string) ($body['code'] ?? ''), (string) ($body['deviceId'] ?? ''), (string) ($body['challenge'] ?? ''), array_values(array_filter($scopes, 'is_string')));
            return $this->responses->success(['deviceCredential' => $credential->credential, 'expiresAt' => $credential->expiresAt->format(\DateTimeInterface::ATOM), 'scopes' => $credential->scopes, 'siteId' => $credential->siteId], $requestId);
        } catch (RequestLimitException $exception) {
            return $this->responses->error($exception->status, $exception->errorCode, $exception->getMessage(), $requestId);
        } catch (PairingException $exception) {
            return $this->responses->error(422, $exception->errorCode, $exception->getMessage(), $requestId);
        }
    }

    public function renewPairing(object $request): object|array
    {
        $requestId = $this->requestId($request);
        try {
            $authorization = trim($this->header($request, 'authorization'));
            if (!preg_match('/^Bearer[ ]+([^ ]+)$/i', $authorization, $matches)) {
                throw new PairingException('invalid_credential', 'Authentication failed.');
            }
            $credential = $this->pairing->renew($matches[1]);
            return $this->responses->success(['deviceCredential' => $credential->credential, 'expiresAt' => $credential->expiresAt->format(\DateTimeInterface::ATOM), 'scopes' => $credential->scopes, 'siteId' => $credential->siteId], $requestId);
        } catch (PairingException $exception) {
            return $this->responses->error(401, $exception->errorCode, $exception->getMessage(), $requestId);
        }
    }

    public function deletePairing(object $request): array
    {
        $requestId = $this->requestId($request);
        $pairingId = (string) $this->param($request, 'pairingId');
        $userId = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        $this->pairing->revokePairing($pairingId, $userId);
        return $this->responses->success(['revoked' => true], $requestId);
    }

    public function stage(object $request): object|array
    {
        $requestId = $this->requestId($request);
        try {
            $device = $this->device($request, 'import:write');
            if ($this->header($request, 'content-type') !== 'application/json') {
                return $this->responses->error(415, 'unsupported-media', 'Content-Type must be application/json.', $requestId);
            }
            $body = $this->limits->decodeJson((string) $request->get_body());
            if (!is_array($body['manifest'] ?? null) || !is_array($body['document'] ?? null)) {
                return $this->responses->error(422, 'invalid-import', 'Manifest and document are required.', $requestId);
            }
            $receipt = $this->imports->stage($body['manifest'], $body['document'], $device);
            return $this->responses->success(['importId' => $receipt->importId, 'missingAssets' => $receipt->missingAssets, 'next' => $receipt->next], $requestId);
        } catch (RequestLimitException $exception) {
            return $this->responses->error($exception->status, $exception->errorCode, $exception->getMessage(), $requestId);
        } catch (\InvalidArgumentException $exception) {
            return $this->responses->error(422, 'invalid-import', $exception->getMessage(), $requestId);
        } catch (PairingException) {
            return $this->responses->error(401, 'invalid-credential', 'Authentication failed.', $requestId);
        }
    }

    public function asset(object $request): object|array
    {
        $requestId = $this->requestId($request);
        try {
            $device = $this->device($request, 'asset:write');
            $sha256 = (string) $this->param($request, 'sha256');
            $mime = strtolower(trim($this->header($request, 'content-type')));
            $bytes = (string) $request->get_body();
            $this->limits->assertAsset($bytes, $sha256, $mime);
            $receipt = $this->imports->attachAsset((string) $this->param($request, 'importId'), $device, $sha256, $bytes, $mime);
            return $this->responses->success(['sha256' => $receipt->sha256, 'mime' => $receipt->mime, 'byteLength' => $receipt->byteLength], $requestId);
        } catch (RequestLimitException $exception) {
            return $this->responses->error($exception->status, $exception->errorCode, $exception->getMessage(), $requestId);
        } catch (PairingException) {
            return $this->responses->error(401, 'invalid-credential', 'Authentication failed.', $requestId);
        } catch (\OutOfRangeException) {
            return $this->responses->error(404, 'import-not-found', 'Import was not found.', $requestId);
        }
    }

    public function commit(object $request): object|array
    {
        $requestId = $this->requestId($request);
        try {
            $device = $this->device($request, 'import:write');
            $key = $this->header($request, 'idempotency-key');
            $base = $this->header($request, 'if-match');
            if ($key === '') {
                return $this->responses->error(400, 'missing-idempotency-key', 'Idempotency-Key is required.', $requestId);
            }
            $snapshot = $this->imports->commit((string) $this->param($request, 'importId'), $device, trim($base, '"'), $key);
            return $this->responses->success(['snapshotId' => $snapshot->snapshotId, 'designId' => $snapshot->designId, 'revision' => $snapshot->revision, 'contentHash' => $snapshot->contentHash], $requestId, $snapshot->revision);
        } catch (PairingException) {
            return $this->responses->error(401, 'invalid-credential', 'Authentication failed.', $requestId);
        } catch (\OutOfRangeException) {
            return $this->responses->error(404, 'import-not-found', 'Import was not found.', $requestId);
        } catch (\RuntimeException $exception) {
            return $this->responses->error(str_contains($exception->getMessage(), 'Idempotency') ? 409 : 422, 'import-not-ready', $exception->getMessage(), $requestId);
        }
    }

    public function status(object $request): object|array
    {
        $requestId = $this->requestId($request);
        try {
            $device = $this->device($request, 'import:write');
            return $this->responses->success($this->imports->status((string) $this->param($request, 'importId'), $device), $requestId);
        } catch (PairingException) {
            return $this->responses->error(401, 'invalid-credential', 'Authentication failed.', $requestId);
        } catch (\OutOfRangeException) {
            return $this->responses->error(404, 'import-not-found', 'Import was not found.', $requestId);
        }
    }

    /** Lists the pages the paired user may edit, so the Figma client can target one. */
    public function pages(object $request): object|array
    {
        $requestId = $this->requestId($request);
        try {
            $device = $this->device($request, 'import:write');
            if (!function_exists('get_posts')) {
                return $this->responses->error(501, 'pages-unavailable', 'The WordPress post API is unavailable.', $requestId);
            }
            $pages = [];
            foreach (get_posts(['post_type' => 'page', 'post_status' => ['publish', 'draft', 'private'], 'numberposts' => self::MAX_PAGES, 'orderby' => 'title', 'order' => 'ASC', 'suppress_filters' => false]) as $page) {
                if (!$this->userCanEdit($device->userId, (int) $page->ID)) {
                    continue;
                }
                $pages[] = ['id' => (int) $page->ID, 'title' => (string) $page->post_title, 'status' => (string) $page->post_status, 'designId' => (string) get_post_meta((int) $page->ID, self::DESIGN_META, true)];
            }
            return $this->responses->success(['pages' => $pages], $requestId);
        } catch (PairingException) {
            return $this->responses->error(401, 'invalid-credential', 'Authentication failed.', $requestId);
        }
    }

    /** Remembers which design a page renders, so the Elementor widget needs no manual ID. */
    public function attachDesign(object $request): object|array
    {
        $requestId = $this->requestId($request);
        try {
            $device = $this->device($request, 'import:write');
            $body = $this->limits->decodeJson((string) $request->get_body());
            $designId = (string) ($body['designId'] ?? '');
            if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/', $designId) !== 1) {
                return $this->responses->error(422, 'invalid-design-id', 'A valid design ID is required.', $requestId);
            }
            $pageId = (int) $this->param($request, 'pageId');
            if (!function_exists('get_post') || !is_object(get_post($pageId))) {
                return $this->responses->error(404, 'page-not-found', 'Page was not found.', $requestId);
            }
            if (!$this->userCanEdit($device->userId, $pageId)) {
                return $this->responses->error(403, 'page-forbidden', 'The paired user cannot edit this page.', $requestId);
            }
            update_post_meta($pageId, self::DESIGN_META, $designId);
            return $this->responses->success(['pageId' => $pageId, 'designId' => $designId], $requestId);
        } catch (RequestLimitException $exception) {
            return $this->responses->error($exception->status, $exception->errorCode, $exception->getMessage(), $requestId);
        } catch (PairingException) {
            return $this->responses->error(401, 'invalid-credential', 'Authentication failed.', $requestId);
        }
    }

    /** Converts a committed snapshot into native Elementor elements on a page. */
    public function transpileToPage(object $request): object|array
    {
        $requestId = $this->requestId($request);
        try {
            $device = $this->device($request, 'import:write');
            $pageId = (int) $this->param($request, 'pageId');
            if (!function_exists('get_post') || !is_object(get_post($pageId))) {
                return $this->responses->error(404, 'page-not-found', 'Page was not found.', $requestId);
            }
            if (!$this->userCanEdit($device->userId, $pageId)) {
                return $this->responses->error(403, 'page-forbidden', 'The paired user cannot edit this page.', $requestId);
            }
            $body = $this->limits->decodeJson((string) $request->get_body());
            $designId = (string) ($body['designId'] ?? '');
            if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/', $designId) !== 1) {
                return $this->responses->error(422, 'invalid-design-id', 'A valid design ID is required.', $requestId);
            }
            $snapshot = (new \FEM\Infrastructure\WpSnapshotRepository(new \FEM\Infrastructure\Database($GLOBALS['wpdb'])))->findLatest($designId);
            if (!is_array($snapshot) || !is_array($snapshot['document'] ?? null)) {
                return $this->responses->error(404, 'snapshot-not-found', 'No committed snapshot exists for this design.', $requestId);
            }
            // "replace" is destructive, so it stays opt-in; appending is the default.
            $replace = ($body['mode'] ?? 'append') === 'replace';
            $target = (string) ($body['target'] ?? 'elementor');
            if (!in_array($target, ['elementor', 'wordpress-blocks'], true)) {
                return $this->responses->error(422, 'invalid-render-target', 'Target must be elementor or wordpress-blocks.', $requestId);
            }
            if ($target === 'wordpress-blocks') {
                $written = (new \FEM\Blocks\BlockWriter())->write($pageId, $snapshot['document'], $replace);
                return $this->responses->success(['pageId' => $pageId, 'target' => $target, 'mode' => $replace ? 'replace' : 'append', 'added' => $written['added'], 'totalTopLevel' => $written['total'], 'notes' => $written['notes']], $requestId);
            }
            $result = (new \FEM\Elementor\Transpiler())->transpile($snapshot['document']);
            if ($result['elements'] === []) {
                return $this->responses->error(422, 'nothing-to-transpile', 'The snapshot produced no Elementor elements.', $requestId);
            }
            $written = (new \FEM\Elementor\DocumentWriter())->write($pageId, $result['elements'], $replace, $device->userId);
            return $this->responses->success([
                'pageId' => $pageId,
                'target' => $target,
                'mode' => $replace ? 'replace' : 'append',
                'added' => count($result['elements']),
                'totalTopLevel' => $written,
                'notes' => $result['notes'],
            ], $requestId);
        } catch (RequestLimitException $exception) {
            return $this->responses->error($exception->status, $exception->errorCode, $exception->getMessage(), $requestId);
        } catch (PairingException) {
            return $this->responses->error(401, 'invalid-credential', 'Authentication failed.', $requestId);
        } catch (\RuntimeException $exception) {
            return $this->responses->error(500, 'transpile-failed', $exception->getMessage(), $requestId);
        }
    }

    /** Mirrors Figma variables and text styles into the Elementor global kit. */
    public function syncDesignSystem(object $request): object|array
    {
        $requestId = $this->requestId($request);
        try {
            $device = $this->device($request, 'import:write');
            $kitId = function_exists('get_option') ? (int) get_option('elementor_active_kit') : 0;
            if ($kitId <= 0) {
                return $this->responses->error(409, 'kit-unavailable', 'No active Elementor kit was found.', $requestId);
            }
            // Editing the kit rewrites site-wide styling, so require rights over it.
            if (!$this->userCanEdit($device->userId, $kitId)) {
                return $this->responses->error(403, 'kit-forbidden', 'The paired user cannot edit the Elementor kit.', $requestId);
            }
            $body = $this->limits->decodeJson((string) $request->get_body());
            $colors = is_array($body['colors'] ?? null) ? array_values(array_filter($body['colors'], 'is_array')) : [];
            $typography = is_array($body['typography'] ?? null) ? array_values(array_filter($body['typography'], 'is_array')) : [];
            if ($colors === [] && $typography === []) {
                return $this->responses->error(422, 'empty-design-system', 'No colours or text styles were sent.', $requestId);
            }
            return $this->responses->success((new \FEM\Elementor\GlobalStyles())->sync($colors, $typography), $requestId);
        } catch (RequestLimitException $exception) {
            return $this->responses->error($exception->status, $exception->errorCode, $exception->getMessage(), $requestId);
        } catch (PairingException) {
            return $this->responses->error(401, 'invalid-credential', 'Authentication failed.', $requestId);
        } catch (\RuntimeException $exception) {
            return $this->responses->error(409, 'kit-unavailable', $exception->getMessage(), $requestId);
        }
    }

    /** The bearer acts for one WordPress user; it must not exceed that user's rights. */
    private function userCanEdit(int $userId, int $pageId): bool
    {
        if (!function_exists('user_can')) {
            return false;
        }
        return $userId > 0 && user_can($userId, 'edit_post', $pageId);
    }

    private function device(object $request, string $scope): AuthenticatedDevice
    {
        $authorization = trim($this->header($request, 'authorization'));
        if (!preg_match('/^Bearer[ ]+([^ ]+)$/i', $authorization, $matches)) {
            throw new PairingException('invalid_credential', 'Authentication failed.');
        }
        return $this->pairing->authenticate($matches[1], $scope);
    }

    private function requestId(object $request): string
    {
        $candidate = $this->header($request, 'x-correlation-id');
        return preg_match('/^[a-f0-9-]{20,64}$/i', $candidate) === 1 ? $candidate : $this->uuid();
    }

    private function header(object $request, string $name): string
    {
        return strtolower($name) === 'content-type' ? trim((string) $request->get_header($name)) : trim((string) $request->get_header($name));
    }

    private function param(object $request, string $name): mixed
    {
        return method_exists($request, 'get_param') ? $request->get_param($name) : null;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
