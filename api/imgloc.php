<?php
/**
 * @file api/imgloc.php
 * @description imgloc图床上传适配器（imgloc.com）
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

$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

// ========== 步骤1：获取上传 Token ==========
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'https://imgloc.com/upload.php?action=token',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => is_file(dirname(__DIR__)."/inc/cacert.pem"),
    CURLOPT_SSL_VERIFYHOST => is_file(dirname(__DIR__)."/inc/cacert.pem") ? 2 : 0,
    CURLOPT_CAINFO => dirname(__DIR__)."/inc/cacert.pem",
    CURLOPT_USERAGENT => $ua,
    CURLOPT_HTTPHEADER => [
        'Referer: https://imgloc.com/',
        'Accept: */*',
    ],
]);

$tokenResp = curl_exec($ch);
$httpCodeGet = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
unset($ch);

if ($tokenResp === false || $httpCodeGet !== 200) {
    @unlink($filepath);
    echo json_encode(["code" => 201, "msg" => "连接imgloc.com获取Token失败 [HTTP " . $httpCodeGet . "]，请稍后重试。"]); exit;
}

$tokenData = json_decode($tokenResp, true);
if (!is_array($tokenData) || empty($tokenData['token'])) {
    @unlink($filepath);
    $snippet = mb_substr(trim(strip_tags($tokenResp)), 0, 300);
    echo json_encode([
        "code" => 201,
        "msg" => "无法从imgloc.com获取上传Token。" . ($snippet ? " 响应：" . $snippet : ""),
    ], JSON_UNESCAPED_UNICODE); exit;
}

$uploadToken = $tokenData['token'];

// ========== 步骤2：携带 Token 上传图片 ==========
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'https://imgloc.com/upload.php',
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'image' => new CURLFile($filepath, $fileType, random_name(12).'.'.$extension),
        'token' => $uploadToken,
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_SSL_VERIFYPEER => is_file(dirname(__DIR__)."/inc/cacert.pem"),
    CURLOPT_SSL_VERIFYHOST => is_file(dirname(__DIR__)."/inc/cacert.pem") ? 2 : 0,
    CURLOPT_CAINFO => dirname(__DIR__)."/inc/cacert.pem",
    CURLOPT_USERAGENT => $ua,
    CURLOPT_HTTPHEADER => [
        'Referer: https://imgloc.com/',
        'Accept: */*',
    ],
]);

$uploadimg = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
unset($ch);
@unlink($filepath);

if ($uploadimg === false || $uploadimg === '') {
    echo json_encode(["code" => 201, "msg" => "连接imgloc.com失败：" . ($curlErr ?: '空响应') . " [HTTP " . $httpCode . "]"]); exit;
}

$imgData = json_decode($uploadimg, true);

if (!is_array($imgData)) {
    $snippet = mb_substr(trim(strip_tags($uploadimg)), 0, 300);
    echo json_encode([
        "code" => 201,
        "msg" => "imgloc.com接口返回了非JSON响应 [HTTP " . $httpCode . "]" . ($snippet ? "：" . $snippet : ""),
        "debug_response" => $snippet,
    ], JSON_UNESCAPED_UNICODE); exit;
}

$ok = isset($imgData['ok']) && $imgData['ok'] === true;
$imgUrl = $imgData['url'] ?? '';

if ($ok && $imgUrl !== '' && filter_var($imgUrl, FILTER_VALIDATE_URL)) {
    echo json_encode([
        "code" => 200,
        "msg" => "上传成功！",
        "path" => $imgUrl,
    ], JSON_UNESCAPED_UNICODE);
} elseif ($httpCode === 400) {
    $errorMsg = $imgData['error'] ?? ($imgData['message'] ?? 'Token 无效或已过期');
    echo json_encode(["code" => 201, "msg" => "imgloc.com 请求错误（400）：" . $errorMsg]);
} elseif ($httpCode === 413) {
    echo json_encode(["code" => 201, "msg" => "文件大小超过imgloc.com限制，请尝试上传更小的文件。"]);
} else {
    $errorMsg = $imgData['error'] ?? ($imgData['message'] ?? '未知错误');
    echo json_encode([
        "code" => 201,
        "msg" => "上传失败（HTTP " . $httpCode . "）：" . $errorMsg,
        "debug" => $uploadimg,
    ], JSON_UNESCAPED_UNICODE);
}

?>
