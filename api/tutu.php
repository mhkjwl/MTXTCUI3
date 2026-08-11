<?php
/**
 * @file api/tutu.php
 * @description TUTU图床上传适配器
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

if(!defined("UPLOAD_GATE")){header("HTTP/1.1 403 Forbidden");exit("Forbidden");}
require ('../inc/lang.php');
require ('../inc/common.php');
header("Content-type:application/json");

// ========== TUTU图床：基于 Telegraph 的图床，无需登录、无需Token、无需Cookie ==========
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

if ($_FILES["file"]["size"] > 5 * 1024 * 1024) {
    echo json_encode(["code" => 201, "msg" => "文件大小超出限制！最大只能上传5MB的文件！"]); exit;
}

$uploadDir = 'upload';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

$tmpName = uniqid() . '.' . $extension;
move_uploaded_file($_FILES["file"]["tmp_name"], $uploadDir . "/" . $tmpName);
$filepath = realpath(dirname(__FILE__)) . "/" . $uploadDir . "/" . $tmpName;

$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

// ========== 上传图片到 tutu.re ==========
// 注意：不要手动设置 Content-Type，由 cURL 根据 CURLFile 自动生成 multipart/form-data boundary
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'https://www.tutu.re/upload',
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => ['file' => new CURLFile($filepath, $fileType, random_name(12).'.'.$extension)],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_SSL_VERIFYPEER => is_file(dirname(__DIR__)."/inc/cacert.pem"),
    CURLOPT_SSL_VERIFYHOST => is_file(dirname(__DIR__)."/inc/cacert.pem") ? 2 : 0,
    CURLOPT_CAINFO => dirname(__DIR__)."/inc/cacert.pem",
    CURLOPT_USERAGENT => $ua,
    CURLOPT_HTTPHEADER => [
        'Origin: https://www.tutu.re',
        'Referer: https://www.tutu.re/',
        'Accept: application/json, text/plain, */*',
    ],
]);

$resp = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
unset($ch);
@unlink($filepath);

if ($resp === false || $resp === '') {
    echo json_encode(["code" => 201, "msg" => "连接TUTU图床失败：" . ($curlErr ?: '空响应') . " [HTTP " . $httpCode . "]"]); exit;
}

// tutu.re 成功返回 JSON 数组，形如 [{"src": "/file/xxx.png"}]
$imgData = json_decode($resp, true);

if (!is_array($imgData)) {
    $snippet = mb_substr(trim(strip_tags($resp)), 0, 300);
    echo json_encode(["code" => 201, "msg" => "TUTU图床返回非JSON [HTTP " . $httpCode . "]" . ($snippet ? "：" . $snippet : "")], JSON_UNESCAPED_UNICODE); exit;
}

// 取数组首项的 src 字段
$firstItem = is_array($imgData) && isset($imgData[0]) ? $imgData[0] : null;
$src = is_array($firstItem) ? ($firstItem['src'] ?? '') : '';

if ($httpCode === 200 && $src !== '') {
    // 拼接完整直链
    $imgUrl = 'https://www.tutu.re' . $src;
    echo json_encode(["code" => 200, "msg" => "上传成功！", "path" => $imgUrl], JSON_UNESCAPED_UNICODE);
} elseif ($httpCode === 413) {
    echo json_encode(["code" => 201, "msg" => "文件大小超过TUTU图床限制（5MB）。"]);
} elseif ($httpCode === 400) {
    echo json_encode(["code" => 201, "msg" => "TUTU图床请求错误（400），可能缺少 file 字段。"]);
} else {
    $errorMsg = is_array($firstItem) && isset($firstItem['error']) ? $firstItem['error'] : '未知错误';
    echo json_encode(["code" => 201, "msg" => "上传失败（HTTP " . $httpCode . "）：" . $errorMsg, "debug" => $resp], JSON_UNESCAPED_UNICODE);
}

?>
