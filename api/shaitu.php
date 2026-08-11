<?php
/**
 * @file api/shaitu.php
 * @description 晒图床上传适配器（https://www.img.st/），基于 Chevereto 3 程序
 *              免登录匿名上传，但需先 GET 上传页面提取 auth_token（CSRF 防护）并保持 Cookie 会话
 *              上传字段：source + action=upload + type=file + privacy + timestamp + auth_token + nsfw
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

// 允许上传的文件类型
$fileType = isset($_FILES["file"]["type"]) ? $_FILES["file"]["type"] : '';
$allowedTypes = ["image/gif", "image/jpeg", "image/jpg", "image/pjpeg", "image/x-png", "image/png", "image/webp", "image/bmp"];

if (!in_array($fileType, $allowedTypes)) {
    echo json_encode(["code" => 201, "msg" => "只允许上传gif、jpeg、jpg、png、webp、bmp格式的图片文件！"], JSON_UNESCAPED_UNICODE);
    exit;
}

// 允许上传的文件大小（10MB，晒图床限制）
if ($_FILES["file"]["size"] > 10 * 1024 * 1024) {
    echo json_encode(["code" => 201, "msg" => "文件大小超出限制！晒图床最大只能上传10MB的文件！"], JSON_UNESCAPED_UNICODE);
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

// Cookie 临时文件（保持会话）
$cookieFile = sys_get_temp_dir() . '/shaitu_cookie_' . uniqid() . '.txt';

$userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36';

// ========== 步骤 1：GET 上传页面，提取 auth_token 并保存 Cookie ==========
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'https://www.img.st/upload',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => is_file(dirname(__DIR__)."/inc/cacert.pem"),
    CURLOPT_SSL_VERIFYHOST => is_file(dirname(__DIR__)."/inc/cacert.pem") ? 2 : 0,
    CURLOPT_CAINFO => dirname(__DIR__)."/inc/cacert.pem",
    CURLOPT_USERAGENT => $userAgent,
    CURLOPT_HTTPHEADER => [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
    ],
    CURLOPT_COOKIEJAR => $cookieFile,
    CURLOPT_COOKIEFILE => $cookieFile,
]);
$pageHtml = curl_exec($ch);
$pageHttpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$pageErr = curl_error($ch);
unset($ch);

// 提取 auth_token：PF.obj.config.auth_token = "xxxx"
$authToken = '';
if (is_string($pageHtml) && preg_match('/auth_token\s*=\s*["\']([a-f0-9]+)["\']/i', $pageHtml, $m)) {
    $authToken = $m[1];
}

if (empty($authToken)) {
    @unlink($filepath);
    @unlink($cookieFile);
    $snippet = is_string($pageHtml) ? mb_substr($pageHtml, 0, 200) : '空响应';
    echo json_encode(["code" => 201, "msg" => "获取晒图床 auth_token 失败 [HTTP " . $pageHttpCode . "]" . ($pageErr ? "：" . $pageErr : "") . "，响应片段：" . $snippet], JSON_UNESCAPED_UNICODE);
    exit;
}

// ========== 步骤 2：带完整字段 POST 上传 ==========
$endpoint = 'https://www.img.st/json';
$timestamp = round(microtime(true) * 1000);

$headers = [
    'Accept: application/json',
    'Origin: https://www.img.st',
    'Referer: https://www.img.st/upload',
    'X-Requested-With: XMLHttpRequest',
];

// 构建请求参数（Chevereto 上传必需字段）
$postData = [
    'source' => new CURLFile($filepath, $fileType, random_name(12).'.'.$extension),
    'type' => 'file',
    'action' => 'upload',
    'privacy' => 'public',
    'timestamp' => $timestamp,
    'auth_token' => $authToken,
    'nsfw' => '0',
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
    CURLOPT_USERAGENT => $userAgent,
    CURLOPT_COOKIEJAR => $cookieFile,
    CURLOPT_COOKIEFILE => $cookieFile,
]);

$uploadimg = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
unset($ch);

// 删除临时文件
@unlink($filepath);
@unlink($cookieFile);

// 处理curl错误
if ($uploadimg === false || $uploadimg === '') {
    echo json_encode(["code" => 201, "msg" => "连接晒图床失败：" . ($curlErr ?: '空响应') . " [HTTP " . $httpCode . "]"], JSON_UNESCAPED_UNICODE);
    exit;
}

// 解析响应
$imgData = json_decode($uploadimg, true);

if (!is_array($imgData)) {
    $snippet = mb_substr(trim(strip_tags($uploadimg)), 0, 300);
    echo json_encode(["code" => 201, "msg" => "晒图床接口返回了非JSON响应 [HTTP " . $httpCode . "]" . ($snippet ? "：" . $snippet : ""), "debug_response" => $snippet], JSON_UNESCAPED_UNICODE);
    exit;
}

// Chevereto 返回格式：{"status_code":200,"success":{"message":"image uploaded","code":200},"image":{"url":"https://stimg.de/...","display_url":"..."}}
$statusCode = isset($imgData['status_code']) ? (int)$imgData['status_code'] : 0;

if ($statusCode === 200) {
    $imgUrl = isset($imgData['image']['url']) ? $imgData['image']['url'] : (isset($imgData['image']['display_url']) ? $imgData['image']['display_url'] : '');

    if ($imgUrl !== '' && filter_var($imgUrl, FILTER_VALIDATE_URL)) {
        echo json_encode(["code" => 200, "msg" => "上传成功！", "path" => $imgUrl], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(["code" => 201, "msg" => "晒图床返回成功但URL无效", "debug" => $uploadimg], JSON_UNESCAPED_UNICODE);
    }
} elseif ($statusCode === 400) {
    // Chevereto 重复图片会返回 400 + Duplicated 错误，但同时会带上已存在的图片 URL
    $errorMsg = isset($imgData['error']['message']) ? $imgData['error']['message'] : (isset($imgData['status_txt']) ? $imgData['status_txt'] : '参数错误');
    if (stripos($errorMsg, 'Duplicated') !== false || stripos($errorMsg, '重复') !== false) {
        $dupUrl = isset($imgData['image']['url']) ? $imgData['image']['url'] : (isset($imgData['image']['display_url']) ? $imgData['image']['display_url'] : '');
        if ($dupUrl !== '' && filter_var($dupUrl, FILTER_VALIDATE_URL)) {
            echo json_encode(["code" => 200, "msg" => "秒传成功！（图片已存在）", "path" => $dupUrl], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    echo json_encode(["code" => 201, "msg" => "晒图床参数错误（400）：" . $errorMsg], JSON_UNESCAPED_UNICODE);
} elseif ($statusCode === 401 || $statusCode === 403) {
    echo json_encode(["code" => 201, "msg" => "晒图床接口拒绝访问（" . $statusCode . "），auth_token 无效或已过期"], JSON_UNESCAPED_UNICODE);
} elseif ($httpCode === 413) {
    echo json_encode(["code" => 201, "msg" => "文件大小超过晒图床限制。"], JSON_UNESCAPED_UNICODE);
} elseif ($httpCode === 429) {
    echo json_encode(["code" => 201, "msg" => "晒图床请求过于频繁，请稍后再试（429）"], JSON_UNESCAPED_UNICODE);
} else {
    $errorMsg = isset($imgData['status_txt']) ? $imgData['status_txt'] : (isset($imgData['error']['message']) ? $imgData['error']['message'] : '未知错误');
    echo json_encode(["code" => 201, "msg" => "上传失败 [HTTP " . $httpCode . "]：" . $errorMsg, "debug" => $uploadimg], JSON_UNESCAPED_UNICODE);
}

?>
