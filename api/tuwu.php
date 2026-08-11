<?php
/**
 * @file api/tuwu.php
 * @description 图屋图床上传适配器
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

if(!defined("UPLOAD_GATE")){header("HTTP/1.1 403 Forbidden");exit("Forbidden");}
require ('../inc/lang.php');
require ('../inc/common.php');
header("Content-type:application/json");

// ========== 图屋图床：基于 imgwu.com，无需登录、无需Token、无需Cookie ==========
// 接口：POST https://www.imgwu.com/upload
// 请求体：multipart/form-data，字段 file
// 响应：{status:200, success:true, data:{link:"https://i.imgur.com/xxx.png", ...}}
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

$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

// ========== 上传图片到 www.imgwu.com ==========
// 注意：不要手动设置 Content-Type，由 cURL 根据 CURLFile 自动生成 multipart/form-data boundary
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'https://www.imgwu.com/upload',
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'file' => new CURLFile($filepath, $fileType, random_name(12).'.'.$extension),
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
        'Origin: https://www.imgwu.com',
        'Referer: https://www.imgwu.com/',
        'Accept: */*',
    ],
]);

$resp = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
unset($ch);
@unlink($filepath);

if ($resp === false || $resp === '') {
    echo json_encode(["code" => 201, "msg" => "连接图屋图床失败：" . ($curlErr ?: '空响应') . " [HTTP " . $httpCode . "]"]); exit;
}

$imgData = json_decode($resp, true);

if (!is_array($imgData)) {
    $snippet = mb_substr(trim(strip_tags($resp)), 0, 300);
    echo json_encode(["code" => 201, "msg" => "图屋图床返回非JSON [HTTP " . $httpCode . "]" . ($snippet ? "：" . $snippet : "")], JSON_UNESCAPED_UNICODE); exit;
}

// 成功判断：status==200 && success==true && data.id 存在
// 注意：接口返回的 data.link 是 i.imgur.com 原始链接，但实际可用的是 www.imgwu.com/v2/{id}.{ext} 反代链接
$status   = isset($imgData['status']) ? (int)$imgData['status'] : 0;
$success  = $imgData['success'] ?? false;
$imgId    = $imgData['data']['id'] ?? '';
$linkRaw  = $imgData['data']['link'] ?? '';

// 优先用 id + 扩展名拼接 www.imgwu.com/v2/ 链接；失败回退到原始 link
$imgUrl = '';
if ($imgId !== '') {
    // 从 data.type（image/png）或原文件扩展名取后缀
    $ext = '';
    if (!empty($imgData['data']['type']) && strpos($imgData['data']['type'], '/') !== false) {
        $ext = strtolower(substr(strrchr($imgData['data']['type'], '/'), 1));
    }
    if ($ext === '') $ext = $extension ?: 'png';
    $imgUrl = 'https://www.imgwu.com/v2/' . $imgId . '.' . $ext;
} elseif ($linkRaw !== '' && filter_var($linkRaw, FILTER_VALIDATE_URL)) {
    // 回退：把 i.imgur.com 替换为 www.imgwu.com/v2
    $imgUrl = preg_replace('#^https?://i\.imgur\.com/#i', 'https://www.imgwu.com/v2/', $linkRaw);
}

if ($httpCode === 200 && $status === 200 && $success && $imgUrl !== '' && filter_var($imgUrl, FILTER_VALIDATE_URL)) {
    echo json_encode(["code" => 200, "msg" => "上传成功！", "path" => $imgUrl], JSON_UNESCAPED_UNICODE);
} elseif ($httpCode === 403) {
    echo json_encode(["code" => 201, "msg" => "图屋图床禁止访问（403）。"]);
} elseif ($httpCode === 413) {
    echo json_encode(["code" => 201, "msg" => "文件大小超过图屋图床限制（20MB）。"]);
} else {
    $errorMsg = $imgData['data']['error'] ?? ($imgData['message'] ?? '未知错误');
    echo json_encode(["code" => 201, "msg" => "上传失败（HTTP " . $httpCode . "）：" . $errorMsg, "debug" => $resp], JSON_UNESCAPED_UNICODE);
}

?>
