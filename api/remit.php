<?php
/**
 * @file api/remit.php
 * @description Remit图床上传适配器
 * @author AI
 * @version 1.1.0-dev
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

$endpoint = 'https://img.remit.ee/api/upload';

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
        'Origin: https://img.remit.ee',
        'Referer: https://img.remit.ee/',
        'Accept: application/json, text/plain, */*',
    ],
]);

$uploadimg = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
unset($ch);
@unlink($filepath);

if ($uploadimg === false || $uploadimg === '') {
    echo json_encode(["code" => 201, "msg" => "连接Remit图床失败：" . ($curlErr ?: '空响应') . " [HTTP " . $httpCode . "]"]); exit;
}

// 尝试多种响应格式提取文件名
$filename = '';
$imgData = json_decode($uploadimg, true);

if (is_array($imgData)) {
    // 格式1: {"data":{"file_id":"xxx.png"}}
    $filename = $imgData['data']['file_id'] ?? $imgData['data']['filename'] ?? $imgData['data']['name'] ?? '';
    if ($filename === '') {
        // 格式2: {"file_id":"xxx.png"} 或 {"filename":"xxx.png"}
        $filename = $imgData['file_id'] ?? $imgData['filename'] ?? $imgData['name'] ?? '';
    }
    if ($filename === '') {
        // 格式3: {"success":true,"data":{"url":"..."}}
        $filename = $imgData['data']['url'] ?? $imgData['url'] ?? '';
    }
} else {
    // 格式4: 纯文本文件名
    $trimmed = trim($uploadimg);
    if (preg_match('/^[a-zA-Z0-9_\-\.]+$/', $trimmed) && strlen($trimmed) > 10) {
        $filename = $trimmed;
    }
}

if ($filename !== '') {
    // 去掉可能已有的路径前缀，避免重复拼接
    $filename = preg_replace('#^(https?://[^/]+)?/?(api/file/)?#', '', $filename);
    $imgUrl = 'https://img.remit.ee/api/file/' . $filename;

    echo json_encode([
        "code" => 200,
        "msg"  => "上传成功！",
        "path" => $imgUrl,
        "debug_response" => $uploadimg,
    ], JSON_UNESCAPED_UNICODE);
} else {
    $snippet = mb_substr(trim(strip_tags($uploadimg)), 0, 300);
    echo json_encode([
        "code" => 201,
        "msg"  => "Remit图床返回格式无法识别 [HTTP " . $httpCode . "]" . ($snippet ? "：" . $snippet : ""),
        "debug_url" => $endpoint,
        "debug_response" => $snippet,
    ], JSON_UNESCAPED_UNICODE);
}

?>
