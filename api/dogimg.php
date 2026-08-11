<?php
/**
 * @file api/dogimg.php
 * @description 狗狗图床上传适配器（适配 ZYCS-IMG 新版 API：顶层 id + 直接返回 url）
 * @author AI
 * @version 1.2.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

if(!defined("UPLOAD_GATE")){header("HTTP/1.1 403 Forbidden");exit("Forbidden");}
require ('../inc/lang.php');
require ('../inc/common.php');
header("Content-type:application/json");

$file = $_FILES["file"]["name"] ?? '';
if (empty($file)) {
    echo json_encode(["code" => 201, "msg" => "未收到上传文件！"]); exit;
}

$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$fileType = $_FILES["file"]["type"] ?? '';
$allowedTypes = ["image/gif", "image/jpeg", "image/jpg", "image/pjpeg", "image/x-png", "image/png", "image/webp", "image/bmp"];

if (!in_array($fileType, $allowedTypes)) {
    echo json_encode(["code" => 201, "msg" => "只允许上传gif、jpeg、jpg、png、webp、bmp格式的图片文件！"]); exit;
}

if ($_FILES["file"]["size"] > 10 * 1024 * 1024) {
    echo json_encode(["code" => 201, "msg" => "文件大小超出限制！最大只能上传10MB的文件！"]); exit;
}

$uploadDir = 'upload';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

$tmpName = uniqid() . '.' . $extension;
move_uploaded_file($_FILES["file"]["tmp_name"], $uploadDir . "/" . $tmpName);
$filepath = realpath(dirname(__FILE__)) . "/" . $uploadDir . "/" . $tmpName;

$endpoint = 'https://img.nloln.de/upload';

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $endpoint,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => ['file' => new CURLFile($filepath, $fileType, random_name(12).'.'.$extension)],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_SSL_VERIFYPEER => is_file(dirname(__DIR__)."/inc/cacert.pem"),
    CURLOPT_SSL_VERIFYHOST => is_file(dirname(__DIR__)."/inc/cacert.pem") ? 2 : 0,
    CURLOPT_CAINFO => dirname(__DIR__)."/inc/cacert.pem",
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    CURLOPT_HTTPHEADER => [
        'Origin: https://img.nloln.de',
        'Referer: https://img.nloln.de/',
        'Accept: */*',
    ],
]);

// 狗狗图床 API 依赖 imgur，共享 Client-ID 易被限流导致间歇性 502/503，加入重试机制（最多3次尝试）
$uploadimg = false;
$curlErr = '';
$httpCode = 0;
$maxRetries = 2;

for ($attempt = 1; $attempt <= $maxRetries + 1; $attempt++) {
    $uploadimg = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    // 可重试条件：curl 错误 / 空响应 / 5xx 服务端错误
    $isRetryable = ($uploadimg === false || $uploadimg === '')
                || $httpCode === 502 || $httpCode === 503 || $httpCode === 504;

    if (!$isRetryable || $attempt > $maxRetries) {
        break;
    }
    // 等待1秒后重试（imgur 限流通常短暂）
    usleep(1000000);
}

unset($ch);
@unlink($filepath);

if ($uploadimg === false || $uploadimg === '') {
    echo json_encode(["code" => 201, "msg" => "连接狗狗图床失败：" . ($curlErr ?: '空响应') . " [HTTP " . $httpCode . "]"]); exit;
}

$imgData = json_decode($uploadimg, true);

if (!is_array($imgData)) {
    $snippet = mb_substr(trim(strip_tags($uploadimg)), 0, 300);
    echo json_encode(["code" => 201, "msg" => "狗狗图床返回非JSON [HTTP " . $httpCode . "]" . ($snippet ? "：" . $snippet : "")], JSON_UNESCAPED_UNICODE); exit;
}

$success = isset($imgData['success']) && $imgData['success'] === true;

if ($success) {
    // 优先使用 img.nloln.de/v2/ 代理 URL（隐藏 imgur 原始地址）
    $imgFileName = $imgData['fileName'] ?? '';
    $imgId = $imgData['id'] ?? '';
    $directUrl = $imgData['url'] ?? '';
    $imgUrl = '';

    if ($imgFileName !== '') {
        // fileName 已含扩展名（如 ZqMSHu3.png），直接拼接 v2 代理 URL
        $imgUrl = 'https://img.nloln.de/v2/' . $imgFileName;
    } elseif ($imgId !== '') {
        // 通过 id + 上传文件扩展名构造（兼容无 fileName 的响应）
        $imgExt = $extension ?: 'png';
        $imgUrl = 'https://img.nloln.de/v2/' . $imgId . '.' . $imgExt;
    } elseif ($directUrl !== '' && filter_var($directUrl, FILTER_VALIDATE_URL)) {
        // 兜底：使用 API 返回的直接 URL（imgur/imgbb 原始地址）
        $imgUrl = $directUrl;
    }

    if ($imgUrl !== '') {
        echo json_encode(["code" => 200, "msg" => "上传成功！", "path" => $imgUrl], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // success=true 但既无 fileName/id 也无 url，属于异常响应
    echo json_encode(["code" => 201, "msg" => "上传成功但图床未返回图片地址", "debug" => $uploadimg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($httpCode === 400) {
    echo json_encode(["code" => 201, "msg" => "狗狗图床请求错误（400）：" . ($imgData['error'] ?? '缺少 file 字段')]);
} elseif ($httpCode === 413) {
    echo json_encode(["code" => 201, "msg" => "文件大小超过狗狗图床限制。"]);
} elseif ($httpCode === 429) {
    echo json_encode(["code" => 201, "msg" => "狗狗图床请求频率超限（429），请稍后再试。"]);
} else {
    $errorMsg = $imgData['error'] ?? '未知错误';
    echo json_encode(["code" => 201, "msg" => "上传失败（HTTP " . $httpCode . "）：" . $errorMsg, "debug" => $uploadimg], JSON_UNESCAPED_UNICODE);
}

?>
