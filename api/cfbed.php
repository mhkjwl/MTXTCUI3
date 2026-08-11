<?php
/**
 * @file api/cfbed.php
 * @description 自建图床上传适配器（Cloudflare/自建 Chevereto 类）
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

if(!defined("UPLOAD_GATE")){header("HTTP/1.1 403 Forbidden");exit("Forbidden");}
require ('../inc/lang.php');
require ('../inc/common.php');
header("Content-type:application/json");

$baseUrl = rtrim($conf['api_cfbed_url'] ?? '', '/');
if (empty($baseUrl)) {
    echo json_encode(["code" => 201, "msg" => "请先在后台API接口设置中填写自建图床的API链接后再上传图片！"]); exit;
}

$apiToken = trim($conf['api_cfbed_token'] ?? '');
if (empty($apiToken)) {
    echo json_encode(["code" => 201, "msg" => "请先在后台API接口设置中填写自建图床的API Token后再上传图片！"]); exit;
}

$file = $_FILES["file"]["name"] ?? '';
if (empty($file)) {
    echo json_encode(["code" => 201, "msg" => "未收到上传文件！"]); exit;
}

$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$fileType = $_FILES["file"]["type"] ?? '';
$fileSize = (int)($_FILES["file"]["size"] ?? 0);
$allowedTypes = ["image/gif", "image/jpeg", "image/jpg", "image/pjpeg", "image/x-png", "image/png", "image/webp", "image/bmp"];

if (!in_array($fileType, $allowedTypes)) {
    echo json_encode(["code" => 201, "msg" => "只允许上传gif、jpeg、jpg、png、webp、bmp格式的图片文件！"]); exit;
}

if ($fileSize > 100 * 1024 * 1024) {
    echo json_encode(["code" => 201, "msg" => "文件大小超出限制！最大只能上传100MB的文件！"]); exit;
}

$uploadDir = 'upload';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

$tmpName = uniqid() . '.' . $extension;
move_uploaded_file($_FILES["file"]["tmp_name"], $uploadDir . "/" . $tmpName);
$filepath = realpath(dirname(__FILE__)) . "/" . $uploadDir . "/" . $tmpName;

$endpoint = $baseUrl . '/upload?returnFormat=full';
$headers = ['Accept: application/json', 'Authorization: Bearer ' . $apiToken];

// ≤ 20MB 普通上传，> 20MB 分片上传
if ($fileSize <= 20 * 1024 * 1024) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $endpoint,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['file' => new CURLFile($filepath, $fileType, random_name(12).'.'.$extension)],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_SSL_VERIFYPEER => is_file(dirname(__DIR__)."/inc/cacert.pem"),
    CURLOPT_SSL_VERIFYHOST => is_file(dirname(__DIR__)."/inc/cacert.pem") ? 2 : 0,
    CURLOPT_CAINFO => dirname(__DIR__)."/inc/cacert.pem",
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $uploadimg = curl_exec($ch); $curlErr = curl_error($ch); $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);
    @unlink($filepath);
} else {
    // 分片上传（20MB/片）
    $chunkSize = 20 * 1024 * 1024;
    $totalChunks = (int)ceil($fileSize / $chunkSize);

    // 初始化
    $ch = curl_init();
    curl_setopt_array($ch, [CURLOPT_URL => $endpoint . '&initChunked=true', CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['originalFileName' => $file, 'originalFileType' => $fileType, 'totalChunks' => (string)$totalChunks], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => is_file(dirname(__DIR__)."/inc/cacert.pem"),
    CURLOPT_SSL_VERIFYHOST => is_file(dirname(__DIR__)."/inc/cacert.pem") ? 2 : 0,
    CURLOPT_CAINFO => dirname(__DIR__)."/inc/cacert.pem", CURLOPT_HTTPHEADER => $headers]);
    $initResp = curl_exec($ch); unset($ch);
    $initData = json_decode($initResp, true);
    $uploadId = $initData['uploadId'] ?? ($initData['sessionInfo']['uploadId'] ?? '');
    if (empty($uploadId)) { @unlink($filepath); echo json_encode(["code" => 201, "msg" => "分块初始化失败"]); exit; }

    // 逐块上传
    $fp = fopen($filepath, 'rb');
    for ($i = 0; $i < $totalChunks; $i++) {
        fseek($fp, $i * $chunkSize);
        $chunkData = fread($fp, $chunkSize);
        $chunkFile = $filepath . '.chunk_' . $i;
        file_put_contents($chunkFile, $chunkData);
        $ch = curl_init();
        curl_setopt_array($ch, [CURLOPT_URL => $endpoint . '&chunked=true', CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['file' => new CURLFile($chunkFile, 'application/octet-stream'), 'uploadId' => $uploadId, 'chunkIndex' => (string)$i, 'totalChunks' => (string)$totalChunks, 'originalFileName' => $file, 'originalFileType' => $fileType], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_SSL_VERIFYPEER => is_file(dirname(__DIR__)."/inc/cacert.pem"),
    CURLOPT_SSL_VERIFYHOST => is_file(dirname(__DIR__)."/inc/cacert.pem") ? 2 : 0,
    CURLOPT_CAINFO => dirname(__DIR__)."/inc/cacert.pem", CURLOPT_HTTPHEADER => $headers]);
        curl_exec($ch); unset($ch);
        @unlink($chunkFile);
    }
    fclose($fp);

    // 合并
    $ch = curl_init();
    curl_setopt_array($ch, [CURLOPT_URL => $endpoint . '&chunked=true&merge=true', CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['uploadId' => $uploadId, 'totalChunks' => (string)$totalChunks, 'originalFileName' => $file, 'originalFileType' => $fileType], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 300, CURLOPT_SSL_VERIFYPEER => is_file(dirname(__DIR__)."/inc/cacert.pem"),
    CURLOPT_SSL_VERIFYHOST => is_file(dirname(__DIR__)."/inc/cacert.pem") ? 2 : 0,
    CURLOPT_CAINFO => dirname(__DIR__)."/inc/cacert.pem", CURLOPT_HTTPHEADER => $headers]);
    $uploadimg = curl_exec($ch); $curlErr = curl_error($ch); $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); unset($ch);
    @unlink($filepath);
}

if ($uploadimg === false || $uploadimg === '') {
    echo json_encode(["code" => 201, "msg" => "连接自建图床失败：" . ($curlErr ?: '空响应') . " [HTTP " . $httpCode . "]"]); exit;
}

$imgData = json_decode($uploadimg, true);

// URL 提取：所有字段组合 + 正则兜底
$imgUrl = '';
$candidates = [];
if (is_array($imgData)) {
    if (isset($imgData[0]) && is_array($imgData[0])) {
        $candidates[] = $imgData[0]['publicUrl'] ?? '';
        $candidates[] = $imgData[0]['src'] ?? '';
        $candidates[] = $imgData[0]['url'] ?? '';
    }
    $candidates[] = $imgData['publicUrl'] ?? '';
    $candidates[] = $imgData['src'] ?? '';
    $candidates[] = $imgData['url'] ?? '';
    if (isset($imgData['data']) && is_array($imgData['data'])) {
        $candidates[] = $imgData['data']['publicUrl'] ?? '';
        $candidates[] = $imgData['data']['src'] ?? '';
        $candidates[] = $imgData['data']['url'] ?? '';
    }
}
if (preg_match_all('#https?://[^\s",\'<>\[\]{}]+#', $uploadimg, $m)) {
    foreach ($m[0] as $u) $candidates[] = rtrim($u, '.,;:!?)}]');
}
foreach ($candidates as $c) {
    $c = trim($c);
    if ($c === '') continue;
    if (strpos($c, 'http') !== 0) $c = $baseUrl . '/' . ltrim($c, '/');
    $parts = parse_url($c);
    if ($parts) {
        $path = isset($parts['path']) ? implode('/', array_map('rawurlencode', explode('/', $parts['path']))) : '';
        $q = isset($parts['query']) ? '?' . $parts['query'] : '';
        $c = $parts['scheme'] . '://' . $parts['host'] . $path . $q;
    }
    if (filter_var($c, FILTER_VALIDATE_URL)) { $imgUrl = $c; break; }
}

if ($imgUrl !== '') {
    echo json_encode(["code" => 200, "msg" => "上传成功！", "path" => $imgUrl], JSON_UNESCAPED_UNICODE);
} elseif ($httpCode === 401 || $httpCode === 403) {
    echo json_encode(["code" => 201, "msg" => "自建图床认证失败（" . $httpCode . "），请检查API Token是否正确"]);
} elseif ($httpCode === 413) {
    echo json_encode(["code" => 201, "msg" => "文件大小超过自建图床限制。"]);
} else {
    $err = is_array($imgData) ? ($imgData['message'] ?? '未知错误') : mb_substr(trim(strip_tags($uploadimg)), 0, 200);
    echo json_encode(["code" => 201, "msg" => "上传失败（HTTP " . $httpCode . "）：" . $err, "debug" => $uploadimg], JSON_UNESCAPED_UNICODE);
}

?>