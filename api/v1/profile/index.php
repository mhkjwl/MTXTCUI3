<?php
/**
 * @file api/v1/profile/index.php
 * @description picui适配器v1用户资料与配额接口（GET/HEAD，清理过期数据并返回用量）
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    picui_failure('Method Not Allowed', 405, 405);
}

$store = picui_atomic_store(function(&$store) {
    picui_purge_expired($store);
    return true;
});

$sizeBytes = 0;
foreach ($store['images'] as $image) {
    $sizeBytes += (int)($image['bytes'] ?? (($image['size'] ?? 0) * 1024));
}

$username = (string)picui_cfg('picui_username', (string)picui_cfg('admin_user', 'picui'));
$name = (string)picui_cfg('picui_name', (string)picui_cfg('name', $username));
$email = (string)picui_cfg('picui_email', '');
$avatar = picui_cfg('picui_avatar', picui_site_base() . '/favicon.ico');
$capacity = (float)picui_cfg('picui_capacity', '10');
$registeredIp = (string)picui_cfg('picui_registered_ip', ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));

picui_require_auth(false);

picui_json(true, 'ok', [
    'username' => $username,
    'name' => $name,
    'avatar' => $avatar,
    'email' => $email,
    'capacity' => $capacity,
    'size' => round($sizeBytes / 1024 / 1024 / 1024, 4),
    'url' => picui_site_base() . '/',
    'image_num' => count($store['images']),
    'album_num' => count($store['albums']),
    'registered_ip' => $registeredIp,
]);