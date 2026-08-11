<?php
/**
 * @file api/imgbb.php
 * @description ImgBB图床上传适配器
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

// 检查 ImgBB API Key 是否已配置
$apiKey = isset($conf['api_imgbb_key']) ? trim($conf['api_imgbb_key']) : '';
if(empty($apiKey)) {
    $result = array(
        "code" => 201,
        "msg" => "请先在后台API接口设置中填写ImgBB API Key后再上传图片！"
    );
    echo json_encode($result);
    exit;
}

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

// 允许上传的文件类型
$fileType = isset($_FILES["file"]["type"]) ? $_FILES["file"]["type"] : '';
$allowedTypes = ["image/gif", "image/jpeg", "image/jpg", "image/pjpeg", "image/x-png", "image/png", "image/webp", "image/bmp", "image/tiff", "image/x-tiff"];

if (!in_array($fileType, $allowedTypes)) {
    $result = array(
        "code" => 201,
        "msg" => "只允许上传gif、jpeg、jpg、png、webp、bmp、tiff格式的图片文件！"
    );
    echo json_encode($result);
    exit;
}

// 允许上传的文件大小（32MB，ImgBB限制）
if ($_FILES["file"]["size"] > 32 * 1024 * 1024) {
    $result = array(
        "code" => 201,
        "msg" => "文件大小超出限制！最大只能上传32MB的文件！"
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

// ImgBB API 上传接口
$endpoint = 'https://api.imgbb.com/1/upload';

// 构建请求参数（字段名是 image）
$postData = ['image' => new CURLFile($filepath, $fileType, random_name(12).'.'.$extension)];

// 构建URL参数（key 和 expiration 通过 query string 传递）
$queryParams = ['key' => $apiKey];

// 可选：过期时间
$expiration = isset($conf['api_imgbb_expiration']) ? intval($conf['api_imgbb_expiration']) : 0;
if ($expiration >= 60 && $expiration <= 15552000) {
    $queryParams['expiration'] = $expiration;
}

$endpoint .= '?' . http_build_query($queryParams);

// 将文件上传至ImgBB
$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => $endpoint,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postData,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 90,
    CURLOPT_SSL_VERIFYPEER => is_file(dirname(__DIR__)."/inc/cacert.pem"),
    CURLOPT_SSL_VERIFYHOST => is_file(dirname(__DIR__)."/inc/cacert.pem") ? 2 : 0,
    CURLOPT_CAINFO => dirname(__DIR__)."/inc/cacert.pem",
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
        "msg" => "连接ImgBB失败：" . ($curlErr ?: '空响应') . " [HTTP " . $httpCode . "]。提示：ImgBB 服务器在海外，可能需要海外网络环境。"
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
        "msg" => "ImgBB接口返回了非JSON响应 [HTTP " . $httpCode . "]" . ($snippet ? "：" . $snippet : ""),
        "debug_url" => 'https://api.imgbb.com/1/upload',
        "debug_response" => $snippet
    );
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

$success = isset($imgData['success']) && $imgData['success'] === true;

if ($success && isset($imgData['data'])) {
    // 优先取 image.url，其次 url，最后 display_url
    $imgUrl = $imgData['data']['image']['url'] ?? $imgData['data']['url'] ?? $imgData['data']['display_url'] ?? '';

    if ($imgUrl !== '' && filter_var($imgUrl, FILTER_VALIDATE_URL)) {
        $result = array(
            "code" => 200,
            "msg" => "上传成功！",
            "path" => $imgUrl,
            "data" => $imgData['data']
        );
    } else {
        $result = array(
            "code" => 201,
            "msg" => "ImgBB返回成功但未获取到图片URL",
            "debug" => $uploadimg
        );
    }
} elseif ($httpCode === 400) {
    $errorMsg = isset($imgData['error']['message']) ? $imgData['error']['message'] : '请求参数错误';
    $result = array(
        "code" => 201,
        "msg" => "ImgBB参数错误：" . $errorMsg
    );
} elseif ($httpCode === 401 || $httpCode === 403) {
    $result = array(
        "code" => 201,
        "msg" => "ImgBB API Key 无效或权限不足，请检查后台配置的Key是否正确！"
    );
} else {
    $statusCode = isset($imgData['status']) ? (int)$imgData['status'] : $httpCode;
    $errorMsg = isset($imgData['error']['message']) ? $imgData['error']['message'] : '未知错误';
    $result = array(
        "code" => 201,
        "msg" => "上传失败（status " . $statusCode . "）：" . $errorMsg,
        "debug" => $uploadimg
    );
}

// 输出JSON
echo json_encode($result, JSON_UNESCAPED_UNICODE);

?>
