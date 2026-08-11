<?php
/**
 * @file api/scdn.php
 * @description SCDN图床上传适配器（img.scdn.io）
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
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
    $result = array(
        "code" => 201,
        "msg" => "未收到上传文件！"
    );
    echo json_encode($result);
    exit;
}

// 获取文件后缀名
$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

// 允许上传的文件类型（含视频格式，服务端会转码为 GIF/WebP）
$fileType = isset($_FILES["file"]["type"]) ? $_FILES["file"]["type"] : '';
$allowedTypes = [
    "image/gif", "image/jpeg", "image/jpg", "image/pjpeg",
    "image/x-png", "image/png", "image/webp", "image/bmp",
    "image/tiff", "image/x-tiff",
    "video/mp4", "video/webm", "video/quicktime", "video/x-msvideo"
];

if (!in_array($fileType, $allowedTypes)) {
    $result = array(
        "code" => 201,
        "msg" => "不支持的文件格式！仅支持 jpg/png/webp/gif/bmp/tiff/mp4/webm/mov/avi"
    );
    echo json_encode($result);
    exit;
}

// 允许上传的文件大小（20MB，服务端有更精细限制）
if ($_FILES["file"]["size"] > 20 * 1024 * 1024) {
    $result = array(
        "code" => 201,
        "msg" => "文件大小超出限制！最大只能上传20MB的文件！"
    );
    echo json_encode($result);
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

// 当前文件在服务器的路径
$filepath = realpath(dirname(__FILE__)) . "/" . $uploadDirectory . "/" . $newfile;

// SCDN图床API接口地址
$endpoint = 'https://img.scdn.io/api/v1.php';

// 构建请求参数（字段名是 image）
$postData = ['image' => new CURLFile($filepath, $fileType, random_name(12).'.'.$extension)];

// 可选：CDN域名
$cdnDomain = isset($conf['api_scdn_cdn']) && $conf['api_scdn_cdn'] !== '' ? $conf['api_scdn_cdn'] : '';
if ($cdnDomain !== '') {
    $postData['cdn_domain'] = $cdnDomain;
}

// 可选：输出格式
$outputFormat = isset($conf['api_scdn_format']) && $conf['api_scdn_format'] !== '' ? $conf['api_scdn_format'] : '';
if ($outputFormat !== '') {
    $postData['outputFormat'] = $outputFormat;
}

// 可选：存储位置
$storageDest = isset($conf['api_scdn_storage']) && $conf['api_scdn_storage'] !== '' ? $conf['api_scdn_storage'] : '';
if ($storageDest !== '') {
    $postData['storage_destination'] = $storageDest;
}

// 将文件上传至SCDN图床
$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => $endpoint,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postData,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT => 90,
    CURLOPT_SSL_VERIFYPEER => is_file(dirname(__DIR__)."/inc/cacert.pem"),
    CURLOPT_SSL_VERIFYHOST => is_file(dirname(__DIR__)."/inc/cacert.pem") ? 2 : 0,
    CURLOPT_CAINFO => dirname(__DIR__)."/inc/cacert.pem",
    CURLOPT_USERAGENT => 'SCDN-API-Client/1.0',
]);

// 发起请求
$uploadimg = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
unset($ch);

// 删除临时文件
@unlink($filepath);

// 处理curl错误
if ($uploadimg === false || $uploadimg === '') {
    $result = array(
        "code" => 201,
        "msg" => "连接SCDN图床失败：" . ($curlErr ?: '空响应') . " [HTTP " . $httpCode . "]。提示：该图床可能需要海外网络环境，请确认服务器能正常访问 img.scdn.io。"
    );
    echo json_encode($result);
    exit;
}

// 解析响应
$imgData = json_decode($uploadimg, true);

if (!is_array($imgData)) {
    $snippet = mb_substr(trim(strip_tags($uploadimg)), 0, 300);
    $result = array(
        "code" => 201,
        "msg" => "SCDN图床接口返回了非JSON响应 [HTTP " . $httpCode . "]" . ($snippet ? "：" . $snippet : ""),
        "debug_url" => $endpoint,
        "debug_response" => $snippet
    );
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

$success = isset($imgData['success']) && $imgData['success'] === true;

if ($success) {
    // 优先取 data.url，其次取顶层 url
    $imgUrl = isset($imgData['data']['url']) ? $imgData['data']['url'] : '';
    if ($imgUrl === '') {
        $imgUrl = isset($imgData['url']) ? $imgData['url'] : '';
    }

    $isDup = (isset($imgData['message']) && strpos($imgData['message'], '秒传') !== false);

    if ($imgUrl !== '' && filter_var($imgUrl, FILTER_VALIDATE_URL)) {
        $result = array(
            "code" => 200,
            "msg" => $isDup ? "秒传成功！（图片已存在）" : "上传成功！",
            "path" => $imgUrl,
            "data" => $imgData['data']
        );
    } else {
        $result = array(
            "code" => 201,
            "msg" => "SCDN图床返回成功但未获取到图片URL",
            "debug" => $uploadimg
        );
    }
} elseif ($httpCode === 429) {
    $result = array(
        "code" => 201,
        "msg" => "SCDN图床请求频率超限（5次/5秒 或 120次/60秒），请稍后再试！"
    );
} elseif ($httpCode === 413) {
    $result = array(
        "code" => 201,
        "msg" => "文件大小超过SCDN图床限制，请尝试上传更小的文件。"
    );
} elseif ($httpCode === 415) {
    $result = array(
        "code" => 201,
        "msg" => "文件类型不被SCDN图床支持，仅支持 jpg/png/webp/gif/bmp/tiff 图片或 mp4/webm/mov/avi 短视频。"
    );
} else {
    $errorMsg = isset($imgData['error']) ? $imgData['error'] : (isset($imgData['message']) ? $imgData['message'] : '未知错误');
    $result = array(
        "code" => 201,
        "msg" => "上传失败（HTTP " . $httpCode . "）：" . $errorMsg,
        "debug" => $uploadimg
    );
}

// 输出JSON
echo json_encode($result, JSON_UNESCAPED_UNICODE);

?>
