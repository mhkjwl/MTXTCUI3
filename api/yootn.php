<?php
/**
 * @file api/yootn.php
 * @description 友藤图床上传适配器（tuchuang.yootn.com）
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

if(!defined("UPLOAD_GATE")){header("HTTP/1.1 403 Forbidden");exit("Forbidden");}
/**
 * 友藤图床 API（tuchuang.yootn.com）
 * 文档：POST https://tuchuang.yootn.com/upimg.php
 * 字段：file（multipart/form-data）
 * 无需 Token / Cookie
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

// tuchuang.yootn.com 上传接口（无需登录 / Token）
$endpoint = 'https://tuchuang.yootn.com/upimg.php';

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
        'Origin: https://tuchuang.yootn.com',
        'Referer: https://tuchuang.yootn.com/',
        'Accept: */*',
    ],
]);

$uploadimg = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
unset($ch);
@unlink($filepath);

if ($uploadimg === false || $uploadimg === '') {
    echo json_encode(["code" => 201, "msg" => "连接友藤图床失败：" . ($curlErr ?: '空响应') . " [HTTP " . $httpCode . "]"]); exit;
}

$imgData = json_decode($uploadimg, true);

if (!is_array($imgData)) {
    $snippet = mb_substr(trim(strip_tags($uploadimg)), 0, 300);
    echo json_encode(["code" => 201, "msg" => "友藤图床返回非JSON [HTTP " . $httpCode . "]" . ($snippet ? "：" . $snippet : "")], JSON_UNESCAPED_UNICODE); exit;
}

$code = $imgData['code'] ?? -1;
$imgUrl = $imgData['msg'] ?? '';

if ($code === 0 && $imgUrl !== '' && filter_var($imgUrl, FILTER_VALIDATE_URL)) {
    echo json_encode(["code" => 200, "msg" => "上传成功！", "path" => $imgUrl], JSON_UNESCAPED_UNICODE);
} elseif ($httpCode === 400) {
    echo json_encode(["code" => 201, "msg" => "友藤图床请求错误（400）：缺少 file 字段或文件未正确传输"]);
} elseif ($httpCode === 413) {
    echo json_encode(["code" => 201, "msg" => "友藤图床文件过大（413）"]);
} elseif ($httpCode === 500) {
    echo json_encode(["code" => 201, "msg" => "友藤图床服务端错误（500），请稍后重试"]);
} else {
    $errorMsg = is_string($imgUrl) && $imgUrl !== '' ? $imgUrl : '未知错误';
    echo json_encode(["code" => 201, "msg" => "上传失败（HTTP " . $httpCode . "）：" . $errorMsg, "debug" => $uploadimg], JSON_UNESCAPED_UNICODE);
}