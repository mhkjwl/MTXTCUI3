<?php
/**
 * @file api/v1/_bootstrap.php
 * @description picui适配器v1公共引导文件，定义辅助函数、鉴权与存储读写
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

if (!defined('IN_CRONLITE')) {
    define('IN_CRONLITE', true);
}

require_once dirname(__DIR__, 2) . '/inc/common.php';

header('Content-Type: application/json; charset=utf-8');

function picui_cfg(string $key, $default = '') {
    global $conf;
    return isset($conf[$key]) && $conf[$key] !== '' ? $conf[$key] : $default;
}

function picui_site_base(): string {
    global $siteurl;
    return rtrim($siteurl, '/');
}

function picui_public_url(string $relative): string {
    return picui_site_base() . '/api/v1/uploads/' . ltrim(str_replace('\\', '/', $relative), '/');
}

function picui_store_path(): string {
    return __DIR__ . '/data/store.json';
}

function picui_default_store(): array {
    return [
        'version' => 1,
        'strategies' => [],
        'albums' => [],
        'images' => [],
        'tokens' => [],
    ];
}

function picui_ensure_store_dir(): void {
    $dir = dirname(picui_store_path());
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

function picui_load_store(): array {
    picui_ensure_store_dir();
    $path = picui_store_path();
    if (!file_exists($path)) {
        $store = picui_default_store();
        picui_save_store($store);
        return $store;
    }
    // 加共享锁读取，防止读到写一半的损坏数据
    $fp = @fopen($path, 'r');
    if (!$fp) {
        $store = picui_default_store();
        picui_save_store($store);
        return $store;
    }
    @flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    @flock($fp, LOCK_UN);
    @fclose($fp);
    $store = json_decode($raw ?: '', true);
    if (!is_array($store)) {
        $store = picui_default_store();
        picui_save_store($store);
        return $store;
    }
    $store += picui_default_store();
    foreach (['strategies', 'albums', 'images', 'tokens'] as $key) {
        if (!isset($store[$key]) || !is_array($store[$key])) {
            $store[$key] = picui_default_store()[$key];
        }
    }
    return $store;
}

function picui_save_store(array $store): void {
    picui_ensure_store_dir();
    file_put_contents(picui_store_path(), json_encode($store, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
}

/**
 * 原子读写：用 flock(LOCK_EX) 包裹整个读-改-写流程，防止并发丢数据
 * 用法：$store = picui_atomic_store(function(&$store) { $store['images'][] = $img; });
 * 回调返回 true 表示需要写回，false 表示只读不写
 */
function picui_atomic_store(callable $fn): array {
    picui_ensure_store_dir();
    $path = picui_store_path();
    $fp = fopen($path, 'c+');
    if (!$fp) return picui_load_store();
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $store = json_decode($raw ?: '', true);
    if (!is_array($store)) $store = picui_default_store();
    $store += picui_default_store();
    foreach (['strategies', 'albums', 'images', 'tokens'] as $key) {
        if (!isset($store[$key]) || !is_array($store[$key])) {
            $store[$key] = picui_default_store()[$key];
        }
    }
    $shouldSave = $fn($store);
    if ($shouldSave) {
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($store, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        fflush($fp);
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $store;
}

function picui_json(bool $status, string $message, array $data = [], int $httpStatus = 200, array $legacy = []): void {
    http_response_code($httpStatus);
    $payload = [
        'status' => $status,
        'message' => $message,
        'data' => (object)$data,
    ];
    if ($legacy) {
        $payload = array_merge($payload, $legacy);
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function picui_request_headers(): array {
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            return $headers;
        }
    }
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') === 0) {
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            $headers[$name] = $value;
        }
    }
    return $headers;
}

function picui_bearer_token(): string {
    $headers = picui_request_headers();
    $auth = $headers['Authorization'] ?? ($headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if (!$auth && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if (preg_match('/Bearer\s+(.+)$/i', trim((string)$auth), $m)) {
        return trim($m[1]);
    }
    return '';
}

function picui_auth_enabled(): bool {
    return (string)picui_cfg('picui_enable', '1') === '1';
}

function picui_expected_token(): string {
    $token = trim((string)picui_cfg('picui_token', ''));
    if ($token !== '') {
        return $token;
    }
    return '';
}

/**
 * 会话级认证：已登录的管理员或前端用户可直接访问 API（无需 Bearer Token）
 * common.php 已加载 member.php（$islogin）和 user_auth.php（$isUserLoggedIn）
 */
function picui_session_authenticated(): bool {
    // 管理员已登录
    if (isset($GLOBALS['islogin']) && $GLOBALS['islogin'] == 1) {
        return true;
    }
    // 前端用户已登录
    if (isset($GLOBALS['isUserLoggedIn']) && $GLOBALS['isUserLoggedIn']) {
        return true;
    }
    return false;
}

function picui_require_auth(bool $guestAllowed = false): bool {
    if (!$guestAllowed && !picui_auth_enabled()) {
        picui_json(false, '接口功能已关闭', [], 403, ['code' => 403, 'msg' => '接口功能已关闭']);
    }

    // 优先检查会话级认证：已登录的管理员/用户可直接访问，无需 Bearer Token
    if (picui_session_authenticated()) {
        return true;
    }

    $expected = picui_expected_token();
    if ($expected === '') {
        // 未配置 Token 时（fail-closed 设计）：
        // - guest-allowed 接口允许匿名访问
        // - 非 guest 接口拒绝访问，要求管理员先配置 API Token
        if ($guestAllowed) {
            return true;
        }
        picui_json(false, '接口鉴权未配置，请联系管理员设置 API Token', [], 403, ['code' => 403, 'msg' => '接口鉴权未配置，请联系管理员设置 API Token']);
    }
    $provided = picui_bearer_token();
    if ($provided !== '' && hash_equals($expected, $provided)) {
        return true;
    }
    if ($guestAllowed && $provided === '') {
        return true;
    }
    picui_json(false, '未登录或授权失败', [], 401, ['code' => 401, 'msg' => '未登录或授权失败']);
}

function picui_next_id(array $items): int {
    $max = 0;
    foreach ($items as $item) {
        $id = (int)($item['id'] ?? 0);
        if ($id > $max) {
            $max = $id;
        }
    }
    return $max + 1;
}

function picui_random_token(int $bytes = 24): string {
    try {
        return bin2hex(random_bytes($bytes));
    } catch (Throwable $e) {
        return sha1(uniqid((string)mt_rand(), true) . microtime(true));
    }
}

function picui_purge_expired(array &$store): void {
    $now = time();
    $store['tokens'] = array_values(array_filter($store['tokens'], function ($token) use ($now) {
        $expiredAt = strtotime((string)($token['expired_at'] ?? ''));
        return $expiredAt === false || $expiredAt > $now;
    }));
}

function picui_normalize_permission($permission): int {
    return ((string)$permission === '0') ? 0 : 1;
}

function picui_filter_order(array $images, string $order): array {
    usort($images, function ($a, $b) use ($order) {
        $map = [
            'newest' => strtotime((string)($b['date'] ?? '')) <=> strtotime((string)($a['date'] ?? '')),
            'earliest' => strtotime((string)($a['date'] ?? '')) <=> strtotime((string)($b['date'] ?? '')),
            'utmost' => ((float)($b['size'] ?? 0)) <=> ((float)($a['size'] ?? 0)),
            'least' => ((float)($a['size'] ?? 0)) <=> ((float)($b['size'] ?? 0)),
        ];
        return $map[$order] ?? $map['newest'];
    });
    return $images;
}

function picui_size_kb(int $bytes): float {
    return round($bytes / 1024, 2);
}

function picui_compute_dimensions(string $path): array {
    if (!function_exists('getimagesize') || !is_file($path)) {
        return [0, 0];
    }
    $info = @getimagesize($path);
    if (!is_array($info)) {
        return [0, 0];
    }
    return [
        (int)($info[0] ?? 0),
        (int)($info[1] ?? 0),
    ];
}

function picui_legacy_upload_payload(array $image): array {
    return [
        'code' => 200,
        'msg' => '上传成功！',
        'path' => $image['links']['url'] ?? '',
    ];
}

function picui_failure(string $message, int $httpStatus = 400, int $code = 201): void {
    picui_json(false, $message, [], $httpStatus, ['code' => $code, 'msg' => $message]);
}

function picui_api_response(array $image, string $message = '上传成功！'): void {
    $payload = [
        'key' => $image['key'],
        'name' => $image['name'],
        'pathname' => $image['pathname'],
        'origin_name' => $image['origin_name'],
        'size' => $image['size'],
        'mimetype' => $image['mimetype'],
        'extension' => $image['extension'],
        'md5' => $image['md5'],
        'sha1' => $image['sha1'],
        'links' => $image['links'],
        'thumbnail_url' => $image['thumbnail_url'],
        'delete_url' => $image['delete_url'],
    ];
    picui_json(true, $message, $payload, 200, picui_legacy_upload_payload($image));
}

function picui_extract_upload_token(array $store, string $token): bool {
    if ($token === '') {
        return false;
    }
    $now = time();
    foreach ($store['tokens'] as $row) {
        if (!isset($row['token'])) {
            continue;
        }
        if (!hash_equals((string)$row['token'], $token)) {
            continue;
        }
        $expiredAt = strtotime((string)($row['expired_at'] ?? ''));
        return $expiredAt === false || $expiredAt > $now;
    }
    return false;
}

function picui_build_image_payload(array $row): array {
    $links = $row['links'] ?? [];
    return [
        'key' => $row['key'] ?? '',
        'name' => $row['name'] ?? '',
        'origin_name' => $row['origin_name'] ?? '',
        'pathname' => $row['pathname'] ?? '',
        'size' => (float)($row['size'] ?? 0),
        'width' => (int)($row['width'] ?? 0),
        'height' => (int)($row['height'] ?? 0),
        'md5' => $row['md5'] ?? '',
        'sha1' => $row['sha1'] ?? '',
        'human_date' => $row['human_date'] ?? '',
        'date' => $row['date'] ?? '',
        'links' => $links,
    ];
}

function picui_find_image_index(array $images, string $key): int {
    foreach ($images as $index => $image) {
        if (($image['key'] ?? '') === $key) {
            return $index;
        }
    }
    return -1;
}