<?php
/**
 * @file api/wentian.php
 * @description WENTIAN图床上传适配器
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

if ($_FILES["file"]["size"] > 5 * 1024 * 1024) {
    echo json_encode(["code" => 201, "msg" => "文件大小超出限制！最大只能上传5MB的文件！"]); exit;
}

$uploadDir = 'upload';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

$tmpName = uniqid() . '.' . $extension;
move_uploaded_file($_FILES["file"]["tmp_name"], $uploadDir . "/" . $tmpName);
$filepath = realpath(dirname(__FILE__)) . "/" . $uploadDir . "/" . $tmpName;

$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

// ========== 步骤1：访问首页获取 Cookie 和 CSRF Token ==========
// 使用临时 Cookie Jar 文件，curl 自动管理 Set-Cookie / Cookie 发送
$cookieJar = $uploadDir . '/wentian_cookie_' . uniqid() . '.tmp';

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'https://aass.de5.net/',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => is_file(dirname(__DIR__)."/inc/cacert.pem"),
    CURLOPT_SSL_VERIFYHOST => is_file(dirname(__DIR__)."/inc/cacert.pem") ? 2 : 0,
    CURLOPT_CAINFO => dirname(__DIR__)."/inc/cacert.pem",
    CURLOPT_COOKIEJAR => $cookieJar,   // 保存服务器下发的 Cookie
    CURLOPT_COOKIEFILE => '',          // 启用 cookie 引擎（空串=内存）
    CURLOPT_USERAGENT => $ua,
]);

$body = curl_exec($ch);
$httpCodeGet = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
unset($ch);

if ($body === false || $httpCodeGet !== 200) {
    @unlink($filepath);
    @unlink($cookieJar);
    echo json_encode(["code" => 201, "msg" => "连接WENTIAN图床获取Cookie失败 [HTTP " . $httpCodeGet . "]" . ($curlErr ? "：" . $curlErr : "")]); exit;
}

// ========== 步骤2：从 HTML 中提取明文 CSRF Token ==========
// Laravel 的 XSRF-TOKEN Cookie 是加密值，真正的 CSRF Token 在 HTML <meta> 中
$csrfToken = '';

// 方式1：<meta name="csrf-token" content="...">
if (preg_match('/<meta[^>]+name=["\']csrf-token["\'][^>]+content=["\']([^"\']+)["\']/i', $body, $m)) {
    $csrfToken = $m[1];
}

// 方式2：JS 变量 window.Laravel.csrfToken = '...'
if ($csrfToken === '' && preg_match("/csrfToken\s*=\s*'([^']+)'/", $body, $m)) {
    $csrfToken = $m[1];
}

// 方式3：<meta name="csrf_token" content="...">（下划线变体）
if ($csrfToken === '' && preg_match('/<meta[^>]+name=["\']csrf_token["\'][^>]+content=["\']([^"\']+)["\']/i', $body, $m)) {
    $csrfToken = $m[1];
}

if ($csrfToken === '') {
    @unlink($filepath);
    @unlink($cookieJar);
    // 输出 HTML 片段用于调试
    $snippet = mb_substr(preg_replace('/\s+/', ' ', strip_tags($body)), 0, 500);
    echo json_encode(["code" => 201, "msg" => "无法从WENTIAN图床首页提取CSRF Token。", "debug_html" => $snippet], JSON_UNESCAPED_UNICODE); exit;
}

// ========== 步骤3：上传图片（使用共享 Cookie Jar） ==========
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'https://aass.de5.net/upload',
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'file' => new CURLFile($filepath, $fileType, random_name(12).'.'.$extension),
        'strategy_id' => 1,
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_SSL_VERIFYPEER => is_file(dirname(__DIR__)."/inc/cacert.pem"),
    CURLOPT_SSL_VERIFYHOST => is_file(dirname(__DIR__)."/inc/cacert.pem") ? 2 : 0,
    CURLOPT_CAINFO => dirname(__DIR__)."/inc/cacert.pem",
    CURLOPT_COOKIEFILE => $cookieJar,  // 读取第一步保存的 Cookie 并自动发送
    CURLOPT_USERAGENT => $ua,
    CURLOPT_HTTPHEADER => [
        'X-CSRF-TOKEN: ' . $csrfToken,
        'X-Requested-With: XMLHttpRequest',
        'Origin: https://aass.de5.net',
        'Referer: https://aass.de5.net/',
        'Accept: application/json, text/javascript, */*; q=0.01',
    ],
]);

$uploadimg = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
unset($ch);

// 清理临时文件
@unlink($filepath);
@unlink($cookieJar);

if ($uploadimg === false || $uploadimg === '') {
    echo json_encode(["code" => 201, "msg" => "连接WENTIAN图床失败：" . ($curlErr ?: '空响应') . " [HTTP " . $httpCode . "]"]); exit;
}

$imgData = json_decode($uploadimg, true);

if (!is_array($imgData)) {
    $snippet = mb_substr(trim(strip_tags($uploadimg)), 0, 300);
    echo json_encode(["code" => 201, "msg" => "WENTIAN图床返回非JSON [HTTP " . $httpCode . "]" . ($snippet ? "：" . $snippet : "")], JSON_UNESCAPED_UNICODE); exit;
}

$status = isset($imgData['status']) && $imgData['status'] === true;
$imgUrl = $imgData['data']['links']['url'] ?? '';

if ($status && $imgUrl !== '' && filter_var($imgUrl, FILTER_VALIDATE_URL)) {
    echo json_encode(["code" => 200, "msg" => "上传成功！", "path" => $imgUrl], JSON_UNESCAPED_UNICODE);
} elseif ($httpCode === 419) {
    echo json_encode(["code" => 201, "msg" => "WENTIAN图床 CSRF Token 不匹配（419）。", "debug_token" => $csrfToken]);
} elseif ($httpCode === 403) {
    echo json_encode(["code" => 201, "msg" => "WENTIAN图床禁止访问（403），Cookie/Token无效。"]);
} elseif ($httpCode === 413) {
    echo json_encode(["code" => 201, "msg" => "文件大小超过WENTIAN图床限制（5MB）。"]);
} else {
    $errorMsg = $imgData['message'] ?? '未知错误';
    echo json_encode(["code" => 201, "msg" => "上传失败（HTTP " . $httpCode . "）：" . $errorMsg, "debug" => $uploadimg], JSON_UNESCAPED_UNICODE);
}

?>
