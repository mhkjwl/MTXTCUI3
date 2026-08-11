<?php
/**
 * @file api/imgtolink.php
 * @description LINK图床上传适配器（https://imgto.link/），免登录匿名上传
 *              三步上传流程：presigned 预签名 → PUT 直传腾讯云 COS → save 保存记录
 *              需配置 anonymousId（匿名用户ID）和 directoryId（目录ID）
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

// 检查 anonymousId 和 directoryId 是否已配置
$anonymousId = isset($conf['api_imgtolink_anonymous_id']) ? trim($conf['api_imgtolink_anonymous_id']) : '';
$directoryId = isset($conf['api_imgtolink_directory_id']) ? trim($conf['api_imgtolink_directory_id']) : '';
if(empty($anonymousId) || empty($directoryId)) {
    echo json_encode(["code" => 201, "msg" => "请先在后台API接口设置中填写 LINK图床的匿名用户ID和目录ID后再上传图片！"], JSON_UNESCAPED_UNICODE);
    exit;
}

// 获取文件
$file = isset($_FILES["file"]["name"]) ? $_FILES["file"]["name"] : '';
if(empty($file)) {
    echo json_encode(["code" => 201, "msg" => "未收到上传文件！"], JSON_UNESCAPED_UNICODE);
    exit;
}

// 获取文件后缀名
$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

// 允许上传的文件类型
$fileType = isset($_FILES["file"]["type"]) ? $_FILES["file"]["type"] : '';
$allowedTypes = ["image/gif", "image/jpeg", "image/jpg", "image/pjpeg", "image/x-png", "image/png", "image/webp", "image/bmp", "image/tiff", "image/x-tiff"];

if (!in_array($fileType, $allowedTypes)) {
    echo json_encode(["code" => 201, "msg" => "只允许上传gif、jpeg、jpg、png、webp、bmp、tiff格式的图片文件！"], JSON_UNESCAPED_UNICODE);
    exit;
}

// 允许上传的文件大小（5MB，LINK图床限制）
if ($_FILES["file"]["size"] > 5 * 1024 * 1024) {
    echo json_encode(["code" => 201, "msg" => "文件大小超出限制！LINK图床最大只能上传5MB的文件！"], JSON_UNESCAPED_UNICODE);
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
$fileSize = filesize($filepath);
$fileContent = file_get_contents($filepath);

// 通用请求头
$userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36';
$cookieStr = 'imgto_link_anonymous_id=' . $anonymousId . '; NEXT_LOCALE=zh-CN';
$originRefererHeaders = [
    'accept: application/json',
    'content-type: application/json',
    'Origin: https://imgto.link',
    'Referer: https://imgto.link/zh-CN/free-image-hosting',
];

// 文件名（使用时间戳 + 随机串）
$timestamp = (string)(int)(microtime(true) * 1000);
$randHex = bin2hex(random_bytes(4));
$filename = $timestamp . '-' . $randHex . '.' . $extension;

// ========== 步骤1：获取预签名 URL ==========
$presignedUrl = 'https://imgto.link/api/v1/upload/presigned';
$presignedBody = json_encode([
    'filename' => $filename,
    'fileSize' => $fileSize,
    'contentType' => $fileType,
    'fileKey' => $filename,
    'directoryPath' => 'public',
    'anonymousId' => $anonymousId,
], JSON_UNESCAPED_UNICODE);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $presignedUrl,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $presignedBody,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => $originRefererHeaders,
    CURLOPT_USERAGENT => $userAgent,
    CURLOPT_COOKIE => $cookieStr,
    CURLOPT_SSL_VERIFYPEER => is_file(dirname(__DIR__)."/inc/cacert.pem"),
    CURLOPT_SSL_VERIFYHOST => is_file(dirname(__DIR__)."/inc/cacert.pem") ? 2 : 0,
    CURLOPT_CAINFO => dirname(__DIR__)."/inc/cacert.pem",
]);

$resp1 = curl_exec($ch);
$curlErr1 = curl_error($ch);
$http1 = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
unset($ch);

if ($resp1 === false || $resp1 === '') {
    @unlink($filepath);
    echo json_encode(["code" => 201, "msg" => "连接LINK图床失败（presigned）：" . ($curlErr1 ?: '空响应') . " [HTTP " . $http1 . "]"], JSON_UNESCAPED_UNICODE);
    exit;
}

$data1 = json_decode($resp1, true);
if (!is_array($data1) || !isset($data1['data']['uploadUrl'])) {
    @unlink($filepath);
    $msg = is_array($data1) && isset($data1['message']) ? $data1['message'] : (is_array($data1) && isset($data1['error']) ? $data1['error'] : '未获取到 uploadUrl');
    echo json_encode(["code" => 201, "msg" => "获取预签名URL失败 [HTTP " . $http1 . "]：" . $msg, "debug" => $resp1], JSON_UNESCAPED_UNICODE);
    exit;
}

$uploadUrl = $data1['data']['uploadUrl'];
$publicUrl = isset($data1['data']['publicUrl']) ? $data1['data']['publicUrl'] : '';
$key = isset($data1['data']['key']) ? $data1['data']['key'] : '';

// ========== 步骤2：PUT 文件到 COS ==========
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $uploadUrl,
    CURLOPT_CUSTOMREQUEST => 'PUT',
    CURLOPT_POSTFIELDS => $fileContent,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 120,
    CURLOPT_HTTPHEADER => [
        'Content-Type: ' . $fileType,
        'Content-Length: ' . $fileSize,
    ],
    CURLOPT_USERAGENT => $userAgent,
    CURLOPT_SSL_VERIFYPEER => is_file(dirname(__DIR__)."/inc/cacert.pem"),
    CURLOPT_SSL_VERIFYHOST => is_file(dirname(__DIR__)."/inc/cacert.pem") ? 2 : 0,
    CURLOPT_CAINFO => dirname(__DIR__)."/inc/cacert.pem",
]);

$resp2 = curl_exec($ch);
$curlErr2 = curl_error($ch);
$http2 = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
unset($ch);

if ($http2 < 200 || $http2 >= 300) {
    @unlink($filepath);
    $snippet = is_string($resp2) ? mb_substr(trim(strip_tags($resp2)), 0, 300) : '空响应';
    echo json_encode(["code" => 201, "msg" => "上传文件到对象存储失败 [HTTP " . $http2 . "]" . ($curlErr2 ? "：" . $curlErr2 : "") . ($snippet ? "：" . $snippet : "")], JSON_UNESCAPED_UNICODE);
    exit;
}

// ========== 步骤3：保存文件记录 ==========
$saveUrl = 'https://imgto.link/api/v1/files/save';
$saveBody = json_encode([
    'key' => $key,
    'filename' => $filename,
    'size' => $fileSize,
    'mimetype' => $fileType,
    'expiresAt' => '2099-12-31T23:59:59.000Z',
    'directoryId' => (int)$directoryId,
], JSON_UNESCAPED_UNICODE);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $saveUrl,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $saveBody,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => $originRefererHeaders,
    CURLOPT_USERAGENT => $userAgent,
    CURLOPT_COOKIE => $cookieStr,
    CURLOPT_SSL_VERIFYPEER => is_file(dirname(__DIR__)."/inc/cacert.pem"),
    CURLOPT_SSL_VERIFYHOST => is_file(dirname(__DIR__)."/inc/cacert.pem") ? 2 : 0,
    CURLOPT_CAINFO => dirname(__DIR__)."/inc/cacert.pem",
]);

$resp3 = curl_exec($ch);
$curlErr3 = curl_error($ch);
$http3 = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
unset($ch);

// 删除临时文件
@unlink($filepath);

if ($resp3 === false || $resp3 === '') {
    echo json_encode(["code" => 201, "msg" => "保存文件记录失败（save）：" . ($curlErr3 ?: '空响应') . " [HTTP " . $http3 . "]"], JSON_UNESCAPED_UNICODE);
    exit;
}

$data3 = json_decode($resp3, true);

// 优先使用 publicUrl（来自 presigned 响应），其次从 save 响应中提取
$imgUrl = '';
if (!empty($publicUrl) && filter_var($publicUrl, FILTER_VALIDATE_URL)) {
    $imgUrl = $publicUrl;
} elseif (is_array($data3)) {
    if (isset($data3['data']['publicPath']) && filter_var($data3['data']['publicPath'], FILTER_VALIDATE_URL)) {
        $imgUrl = $data3['data']['publicPath'];
    } elseif (isset($data3['data']['url']) && filter_var($data3['data']['url'], FILTER_VALIDATE_URL)) {
        $imgUrl = $data3['data']['url'];
    } elseif (isset($data3['data']['rawUrl']) && filter_var($data3['data']['rawUrl'], FILTER_VALIDATE_URL)) {
        $imgUrl = $data3['data']['rawUrl'];
    }
}

if ($imgUrl !== '' && filter_var($imgUrl, FILTER_VALIDATE_URL)) {
    echo json_encode(["code" => 200, "msg" => "上传成功！", "path" => $imgUrl], JSON_UNESCAPED_UNICODE);
} elseif ($http3 === 401) {
    echo json_encode(["code" => 201, "msg" => "LINK图床认证失败（401），anonymousId 无效或已过期，请重新获取"], JSON_UNESCAPED_UNICODE);
} elseif ($http3 === 403) {
    echo json_encode(["code" => 201, "msg" => "LINK图床接口拒绝访问（403），directoryId 无效或权限不足"], JSON_UNESCAPED_UNICODE);
} elseif ($http3 === 429) {
    echo json_encode(["code" => 201, "msg" => "LINK图床请求过于频繁，请稍后再试（429）"], JSON_UNESCAPED_UNICODE);
} else {
    $msg = is_array($data3) && isset($data3['message']) ? $data3['message'] : '未获取到图片URL';
    echo json_encode(["code" => 201, "msg" => "保存文件记录失败 [HTTP " . $http3 . "]：" . $msg, "debug" => $resp3], JSON_UNESCAPED_UNICODE);
}

?>
