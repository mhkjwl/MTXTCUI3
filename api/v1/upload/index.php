<?php
/**
 * @file api/v1/upload/index.php
 * @description picui适配器v1上传接口（POST，鉴权后上传图片）
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

require_once __DIR__ . '/../_bootstrap.php';

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'POST', ['POST'], true)) {
    picui_failure('Method Not Allowed', 405, 405);
}

// 鉴权：与其他 v1 端点保持一致，防止匿名无限上传占用磁盘
picui_require_auth(false);

// 只读加载 store（用于前置校验，不写回）
$store = picui_load_store();

$file = $_FILES['file'] ?? null;
if (!$file || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
    picui_failure('未收到上传文件', 400, 201);
}

$originName = (string)($file['name'] ?? 'file');

// 服务端二进制校验：必须是真实图片，后缀由服务端判定（不信任客户端文件名/MIME）
$allowedImage = [
    IMAGETYPE_GIF  => ['gif', 'image/gif'],
    IMAGETYPE_JPEG => ['jpg', 'image/jpeg'],
    IMAGETYPE_PNG  => ['png', 'image/png'],
    IMAGETYPE_WEBP => ['webp', 'image/webp'],
];
$imageInfo = @getimagesize($file['tmp_name']);
if ($imageInfo === false || !isset($imageInfo[2]) || !isset($allowedImage[$imageInfo[2]])) {
    picui_failure('只允许上传真实的图片文件', 400, 201);
}
$ext = $allowedImage[$imageInfo[2]][0];
$mime = $allowedImage[$imageInfo[2]][1];

$sizeBytes = (int)($file['size'] ?? 0);
if ($sizeBytes > 10 * 1024 * 1024) {
    picui_failure('文件大小超出限制', 400, 201);
}

$tempToken = trim((string)($_POST['token'] ?? ''));
if ($tempToken !== '' && !picui_extract_upload_token($store, $tempToken)) {
    picui_failure('临时上传 Token 无效或已过期', 401, 401);
}

$permission = picui_normalize_permission($_POST['permission'] ?? 1);
$strategyId = (int)($_POST['strategy_id'] ?? 1);
$albumId = (int)($_POST['album_id'] ?? 1);
$expiredAt = trim((string)($_POST['expired_at'] ?? ''));
if ($expiredAt !== '' && strtotime($expiredAt) === false) {
    picui_failure('expired_at 格式不正确', 400, 201);
}

$yearDir = date('Y');
$monthDir = date('m');
$uploadDir = __DIR__ . '/../uploads/' . $yearDir . '/' . $monthDir;
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}

$key = picui_random_token(16);
$safeBase = preg_replace('/[^a-zA-Z0-9_-]+/', '_', pathinfo($originName, PATHINFO_FILENAME));
if ($safeBase === '') {
    $safeBase = 'image';
}
$fileName = $safeBase . '_' . substr($key, 0, 8) . ($ext ? '.' . $ext : '');
$relativePath = $yearDir . '/' . $monthDir . '/' . $fileName;
$targetPath = $uploadDir . '/' . $fileName;
if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    picui_failure('图片保存失败', 500, 500);
}

[$width, $height] = picui_compute_dimensions($targetPath);
$md5 = md5_file($targetPath) ?: '';
$sha1 = sha1_file($targetPath) ?: '';
$humanDate = date('Y-m-d H:i:s');
$bytes = filesize($targetPath) ?: $sizeBytes;
$url = picui_public_url($relativePath);

$image = [
    'key' => $key,
    'name' => $fileName,
    'origin_name' => $originName,
    'pathname' => $relativePath,
    'size' => picui_size_kb((int)$bytes),
    'bytes' => (int)$bytes,
    'mimetype' => $mime,
    'extension' => $ext,
    'md5' => $md5,
    'sha1' => $sha1,
    'width' => $width,
    'height' => $height,
    'permission' => $permission,
    'strategy_id' => $strategyId,
    'album_id' => $albumId,
    'date' => $humanDate,
    'human_date' => $humanDate,
    'expired_at' => $expiredAt,
    'links' => [
        'url' => $url,
        'html' => '<img src="' . $url . '" alt="' . htmlspecialchars($originName, ENT_QUOTES, 'UTF-8') . '">',
        'bbcode' => '[img]' . $url . '[/img]',
        'markdown' => '![' . $originName . '](' . $url . ')',
        'markdown_with_link' => '[![' . $originName . '](' . $url . ')](' . $url . ')',
        'thumbnail_url' => $url,
        'delete_url' => picui_site_base() . '/api/v1/images/' . $key,
    ],
    'thumbnail_url' => $url,
    'delete_url' => picui_site_base() . '/api/v1/images/' . $key,
];

// 用原子读写替代 load_store + save_store，防止并发丢数据
$store = picui_atomic_store(function(&$store) use ($image, $albumId) {
    // 清理过期图片（字段为 expired_at 日期字符串，空值表示永久有效）
    $now = time();
    if (!empty($store['images'])) {
        $store['images'] = array_values(array_filter($store['images'], function($img) use ($now) {
            $exp = trim((string)($img['expired_at'] ?? ''));
            if ($exp === '') return true; // 永久有效
            $expTs = strtotime($exp);
            return $expTs === false || $expTs > $now;
        }));
    }
    // 新增图片记录
    $store['images'][] = $image;
    // 更新相册计数
    foreach ($store['albums'] as &$album) {
        if ((int)($album['id'] ?? 0) === $albumId) {
            $album['image_num'] = (int)($album['image_num'] ?? 0) + 1;
            break;
        }
    }
    unset($album);
    return true; // 写回
});
picui_api_response($image);