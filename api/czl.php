<?php
/**
 * @file api/czl.php
 * @description CZL图床上传适配器
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

if ($_FILES["file"]["size"] > 512 * 1024) {
    echo json_encode(["code" => 201, "msg" => "文件大小超出限制！最大只能上传512KB的文件！"]); exit;
}

$uploadDir = 'upload';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

$tmpName = uniqid() . '.' . $extension;
move_uploaded_file($_FILES["file"]["tmp_name"], $uploadDir . "/" . $tmpName);
$filepath = realpath(dirname(__FILE__)) . "/" . $uploadDir . "/" . $tmpName;

$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

// ========== 步骤1：获取 Cookie 和 CSRF Token ==========
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'https://img.czl.net/',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => is_file(dirname(__DIR__)."/inc/cacert.pem"),
    CURLOPT_SSL_VERIFYHOST => is_file(dirname(__DIR__)."/inc/cacert.pem") ? 2 : 0,
    CURLOPT_CAINFO => dirname(__DIR__)."/inc/cacert.pem",
    CURLOPT_HEADER => true,
    CURLOPT_USERAGENT => $ua,
]);

$response = curl_exec($ch);
$httpCodeGet = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
unset($ch);

if ($response === false || $httpCodeGet !== 200) {
    @unlink($filepath);
    echo json_encode(["code" => 201, "msg" => "连接img.czl.net获取Cookie失败 [HTTP " . $httpCodeGet . "]"]); exit;
}

$respHeaders = substr($response, 0, $headerSize);
$body = substr($response, $headerSize);

// 提取 Cookie 中的 XSRF-TOKEN 和 czl_session
$xsrfToken = '';
$sessionVal = '';
if (preg_match('/Set-Cookie:\s*XSRF-TOKEN=([^;]+)/i', $respHeaders, $m)) {
    $xsrfToken = urldecode(trim($m[1]));
}
if (preg_match('/Set-Cookie:\s*czl_session=([^;]+)/i', $respHeaders, $m)) {
    $sessionVal = trim($m[1]);
}

// 同时从页面 HTML 中尝试提取 X-CSRF-TOKEN（优先 meta 标签，其次 JS 变量）
if (preg_match('/<meta[^>]+name=["\']csrf-token["\'][^>]+content=["\']([^"\']+)["\']/i', $body, $m)) {
    $xsrfToken = $m[1];
}

// 构建 Cookie 字符串
$cookieParts = [];
if ($xsrfToken !== '') $cookieParts[] = 'XSRF-TOKEN=' . $xsrfToken;
if ($sessionVal !== '') $cookieParts[] = 'czl_session=' . $sessionVal;
$cookieStr = implode('; ', $cookieParts);

if (empty($xsrfToken) || empty($sessionVal)) {
    @unlink($filepath);
    $dbg = mb_substr($respHeaders, 0, 500);
    echo json_encode(["code" => 201, "msg" => "无法从img.czl.net获取完整的Cookie/Token。", "debug_headers" => $dbg]); exit;
}

// ========== 步骤2：上传图片 ==========
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'https://img.czl.net/upload',
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
        'Cookie: ' . $cookieStr,
        'X-CSRF-TOKEN: ' . $xsrfToken,
        'X-Requested-With: XMLHttpRequest',
        'Origin: https://img.czl.net',
        'Referer: https://img.czl.net/upload',
        'Accept: application/json, text/javascript, */*; q=0.01',
    ],
]);

$uploadimg = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
unset($ch);
@unlink($filepath);

if ($uploadimg === false || $uploadimg === '') {
    echo json_encode(["code" => 201, "msg" => "连接CZL图床失败：" . ($curlErr ?: '空响应') . " [HTTP " . $httpCode . "]"]); exit;
}

$imgData = json_decode($uploadimg, true);

if (!is_array($imgData)) {
    $snippet = mb_substr(trim(strip_tags($uploadimg)), 0, 300);
    echo json_encode(["code" => 201, "msg" => "CZL图床返回非JSON [HTTP " . $httpCode . "]" . ($snippet ? "：" . $snippet : "")], JSON_UNESCAPED_UNICODE); exit;
}

$status = isset($imgData['status']) && $imgData['status'] === true;
$imgUrl = $imgData['data']['links']['url'] ?? '';

if ($status && $imgUrl !== '' && filter_var($imgUrl, FILTER_VALIDATE_URL)) {
    echo json_encode(["code" => 200, "msg" => "上传成功！", "path" => $imgUrl], JSON_UNESCAPED_UNICODE);
} elseif ($httpCode === 419) {
    echo json_encode(["code" => 201, "msg" => "CZL图床 CSRF Token 不匹配（419）。"]);
} elseif ($httpCode === 403) {
    echo json_encode(["code" => 201, "msg" => "CZL图床禁止访问（403），Cookie/Token无效。"]);
} elseif ($httpCode === 413) {
    echo json_encode(["code" => 201, "msg" => "文件大小超过CZL图床限制（512KB）。"]);
} else {
    $errorMsg = $imgData['message'] ?? '未知错误';
    echo json_encode(["code" => 201, "msg" => "上传失败（HTTP " . $httpCode . "）：" . $errorMsg, "debug" => $uploadimg], JSON_UNESCAPED_UNICODE);
}

?>