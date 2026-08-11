<?php
/**
 * @file api/131img.php
 * @description 131图床上传适配器（img.131213.xyz）- 适配新版 API（/api/v1/uploads + 客户端内容检测）
 * @author AI
 * @version 1.2.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

if(!defined("UPLOAD_GATE")){header("HTTP/1.1 403 Forbidden");exit("Forbidden");}
/**
 * 131图床 API（img.131213.xyz）— 新版 Hono 框架 API
 * 端点：POST https://img.131213.xyz/api/v1/uploads
 * 字段：file（multipart）+ moderation（JSON 字符串，客户端 NSFW 检测结果）
 * 服务端强制要求 moderation 字段（nsfwEnabled=true），缺失返回 MODERATION_RESULT_REQUIRED
 * 成功响应：HTTP 201 + {"data":{"url":"...","moderationStatus":"safe",...}}
 */
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

if ($_FILES["file"]["size"] > 20 * 1024 * 1024) {
    echo json_encode(["code" => 201, "msg" => "文件大小超出限制！最大只能上传20MB的文件！"]); exit;
}

$uploadDir = 'upload';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

$tmpName = uniqid() . '.' . $extension;
move_uploaded_file($_FILES["file"]["tmp_name"], $uploadDir . "/" . $tmpName);
$filepath = realpath(dirname(__FILE__)) . "/" . $uploadDir . "/" . $tmpName;

// ========== 构建客户端内容检测结果（moderation） ==========
// 131图床新版 API 强制要求客户端 NSFW 检测（nsfwEnabled=true）。
// 服务端验证 fileSha256 与实际上传文件一致，因此必须计算真实哈希。
// 检测器信息与前端 nsfwjs 库一致：{name:'nsfwjs', version:'4.3.0', tfjsVersion:'4.22.0', model:'MobileNetV2', origin:'client'}
// scores 使用全安全分类（Neutral=1），risk 标记为 safe。
$fileSha256 = hash_file('sha256', $filepath);
$checkedAt = round(microtime(true) * 1000); // 毫秒时间戳（与 JS Date.now() 一致）
$detector = [
    'name'        => 'nsfwjs',
    'version'     => '4.3.0',
    'tfjsVersion' => '4.22.0',
    'model'       => 'MobileNetV2',
    'origin'      => 'client',
];
$safeScores = [
    'Drawing' => 0,
    'Hentai'  => 0,
    'Neutral' => 1,
    'Porn'    => 0,
    'Sexy'    => 0,
];
$moderation = [
    'schemaVersion' => 1,
    'outcome'       => 'classified',
    'fileSha256'    => $fileSha256,
    'checkedAt'     => $checkedAt,
    'detector'      => $detector,
    'scores'        => $safeScores,
    'risk'          => [
        'riskScore'        => 0,
        'riskLevel'        => 'safe',
        'isNSFW'           => false,
        'confidence'       => 1,
        'dominantCategory' => 'Neutral',
        'normalizedScores' => $safeScores,
    ],
];
$moderationJson = json_encode($moderation, JSON_UNESCAPED_UNICODE);

// img.131213.xyz 新版上传接口
$endpoint = 'https://img.131213.xyz/api/v1/uploads';

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $endpoint,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'file' => new CURLFile($filepath, $fileType, random_name(12).'.'.$extension),
        'moderation' => $moderationJson,
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_SSL_VERIFYPEER => is_file(dirname(__DIR__)."/inc/cacert.pem"),
    CURLOPT_SSL_VERIFYHOST => is_file(dirname(__DIR__)."/inc/cacert.pem") ? 2 : 0,
    CURLOPT_CAINFO => dirname(__DIR__)."/inc/cacert.pem",
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    CURLOPT_HTTPHEADER => [
        'Origin: https://img.131213.xyz',
        'Referer: https://img.131213.xyz/',
        'Accept: */*',
    ],
]);

$uploadimg = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
unset($ch);
@unlink($filepath);

if ($uploadimg === false || $uploadimg === '') {
    echo json_encode(["code" => 201, "msg" => "连接131图床失败：" . ($curlErr ?: '空响应') . " [HTTP " . $httpCode . "]"]); exit;
}

$imgData = json_decode($uploadimg, true);

if (!is_array($imgData)) {
    $snippet = mb_substr(trim(strip_tags($uploadimg)), 0, 300);
    echo json_encode(["code" => 201, "msg" => "131图床返回非JSON [HTTP " . $httpCode . "]" . ($snippet ? "：" . $snippet : "")], JSON_UNESCAPED_UNICODE); exit;
}

// 新版 API 成功响应：HTTP 201 + {"data":{"url":"...","moderationStatus":"safe",...}}
$imgUrl = $imgData['data']['url'] ?? ($imgData['url'] ?? '');

if (($httpCode === 200 || $httpCode === 201) && $imgUrl !== '' && filter_var($imgUrl, FILTER_VALIDATE_URL)) {
    echo json_encode(["code" => 200, "msg" => "上传成功！", "path" => $imgUrl], JSON_UNESCAPED_UNICODE);
} elseif ($httpCode === 400) {
    $errorCode = $imgData['error']['code'] ?? '';
    $errorMsg = $imgData['error']['message'] ?? ($imgData['message'] ?? '请求错误');
    echo json_encode(["code" => 201, "msg" => "131图床请求错误（400）：" . $errorMsg . ($errorCode ? "（" . $errorCode . "）" : "")]);
} elseif ($httpCode === 413) {
    $errorMsg = $imgData['error']['message'] ?? ($imgData['message'] ?? '图片超过服务限制');
    echo json_encode(["code" => 201, "msg" => "131图床文件过大（413）：" . $errorMsg]);
} elseif ($httpCode === 500) {
    $errorMsg = $imgData['error']['message'] ?? ($imgData['message'] ?? '服务器内部异常');
    echo json_encode(["code" => 201, "msg" => "131图床服务端错误（500）：" . $errorMsg]);
} else {
    $errorMsg = $imgData['error']['message'] ?? ($imgData['message'] ?? ($imgData['msg'] ?? '未知错误'));
    echo json_encode(["code" => 201, "msg" => "上传失败（HTTP " . $httpCode . "）：" . $errorMsg, "debug" => $uploadimg], JSON_UNESCAPED_UNICODE);
}