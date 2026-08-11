<?php
/**
 * @file api/guaigua.php
 * @description 乖乖图床上传适配器（https://ihs.aag.moe/），免登录匿名上传，无需 Token/Cookie
 * @author AI
 * @version 1.0.0-dev
 * @date 2026-08-06
 */
declare(strict_types=1);

if(!defined("UPLOAD_GATE")){header("HTTP/1.1 403 Forbidden");exit("Forbidden");}
require ('../inc/lang.php');
require ('../inc/common.php');
// 编码
header("Content-type:application/json");

// 获取文件
$file = isset($_FILES["file"]["name"]) ? $_FILES["file"]["name"] : '';
if(empty($file)) {
    echo json_encode(["code" => 201, "msg" => "未收到上传文件！"], JSON_UNESCAPED_UNICODE);
    exit;
}

// 获取文件后缀名
$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

// 允许上传的文件类型（官网说明：支持 JPG | PNG | GIF | WEBP | AVIF）
$fileType = isset($_FILES["file"]["type"]) ? $_FILES["file"]["type"] : '';
$allowedTypes = ["image/gif", "image/jpeg", "image/jpg", "image/pjpeg", "image/x-png", "image/png", "image/webp", "image/avif"];

if (!in_array($fileType, $allowedTypes)) {
    echo json_encode(["code" => 201, "msg" => "只允许上传jpg、png、gif、webp、avif格式的图片文件！"], JSON_UNESCAPED_UNICODE);
    exit;
}

// 允许上传的文件大小（10MB，乖乖图床限制）
if ($_FILES["file"]["size"] > 10 * 1024 * 1024) {
    echo json_encode(["code" => 201, "msg" => "文件大小超出限制！乖乖图床最大只能上传10MB的文件！"], JSON_UNESCAPED_UNICODE);
    exit;
}

// 上传目录（临时缓存）
$uploadDirectory = 'upload';
if (!is_dir($uploadDirectory)) {
    @mkdir($uploadDirectory, 0755, true);
}

// 临时保存文件
$newfile = uniqid() . '.' . $extension;
move_uploaded_file($_FILES["file"]["tmp_name"], $uploadDirectory . "/" . $newfile);
$filepath = realpath(dirname(__FILE__)) . "/" . $uploadDirectory . "/" . $newfile;

// 乖乖图床 API 上传接口（匿名上传，无鉴权）
$endpoint = 'https://ihs.aag.moe/upload.php';

$headers = [
    'Accept: application/json',
    'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
    'Origin: https://ihs.aag.moe',
    'Referer: https://ihs.aag.moe/',
];

// 构建请求参数（字段名为 file）
$postData = [
    'file' => new CURLFile($filepath, $fileType, random_name(12).'.'.$extension),
];

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $endpoint,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postData,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 90,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_SSL_VERIFYPEER => is_file(dirname(__DIR__)."/inc/cacert.pem"),
    CURLOPT_SSL_VERIFYHOST => is_file(dirname(__DIR__)."/inc/cacert.pem") ? 2 : 0,
    CURLOPT_CAINFO => dirname(__DIR__)."/inc/cacert.pem",
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36',
]);

$uploadimg = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
unset($ch);

// 删除临时文件
@unlink($filepath);

// 处理curl错误
if ($uploadimg === false || $uploadimg === '') {
    echo json_encode(["code" => 201, "msg" => "连接乖乖图床失败：" . ($curlErr ?: '空响应') . " [HTTP " . $httpCode . "]"], JSON_UNESCAPED_UNICODE);
    exit;
}

// 解析响应
$imgData = json_decode($uploadimg, true);

if (!is_array($imgData)) {
    $snippet = mb_substr(trim(strip_tags($uploadimg)), 0, 300);
    echo json_encode(["code" => 201, "msg" => "乖乖图床接口返回了非JSON响应 [HTTP " . $httpCode . "]" . ($snippet ? "：" . $snippet : ""), "debug_response" => $snippet], JSON_UNESCAPED_UNICODE);
    exit;
}

// 乖乖图床返回格式：{"success":true,"file_url":"https://d1.aag.moe/public/..."}
if (isset($imgData['success']) && $imgData['success'] === true) {
    $imgUrl = isset($imgData['file_url']) ? $imgData['file_url'] : (isset($imgData['url']) ? $imgData['url'] : '');

    if ($imgUrl !== '' && filter_var($imgUrl, FILTER_VALIDATE_URL)) {
        echo json_encode(["code" => 200, "msg" => "上传成功！", "path" => $imgUrl], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(["code" => 201, "msg" => "乖乖图床返回成功但URL无效", "debug" => $uploadimg], JSON_UNESCAPED_UNICODE);
    }
} elseif ($httpCode === 401 || $httpCode === 403) {
    echo json_encode(["code" => 201, "msg" => "乖乖图床接口拒绝访问（" . $httpCode . "）"], JSON_UNESCAPED_UNICODE);
} elseif ($httpCode === 413) {
    echo json_encode(["code" => 201, "msg" => "文件大小超过乖乖图床限制。"], JSON_UNESCAPED_UNICODE);
} elseif ($httpCode === 429) {
    echo json_encode(["code" => 201, "msg" => "乖乖图床请求过于频繁，请稍后再试（429）"], JSON_UNESCAPED_UNICODE);
} else {
    $errorMsg = isset($imgData['error']) ? (is_string($imgData['error']) ? $imgData['error'] : (isset($imgData['error']['message']) ? $imgData['error']['message'] : '未知错误')) : (isset($imgData['message']) ? $imgData['message'] : '未知错误');
    echo json_encode(["code" => 201, "msg" => "上传失败 [HTTP " . $httpCode . "]：" . $errorMsg, "debug" => $uploadimg], JSON_UNESCAPED_UNICODE);
}

?>
