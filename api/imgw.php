<?php
/**
 * @file api/imgw.php
 * @description 图网图床上传适配器（https://www.imgw.cc/），Bearer Token 认证
 * @author AI
 * @version 1.3.6-dev
 * @date 2026-08-05
 */
declare(strict_types=1);

if(!defined("UPLOAD_GATE")){header("HTTP/1.1 403 Forbidden");exit("Forbidden");}
require ('../inc/lang.php');
require ('../inc/common.php');
// 编码
header("Content-type:application/json");

// 检查图网图床 Token 是否已配置
$token = isset($conf['api_imgw_token']) ? trim($conf['api_imgw_token']) : '';
if(empty($token)) {
    $result = array(
        "code" => 201,
        "msg" => "请先在后台API接口设置中填写图网图床 Token 后再上传图片！"
    );
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// 获取文件
$file = isset($_FILES["file"]["name"]) ? $_FILES["file"]["name"] : '';
if(empty($file)) {
    $result = array(
        "code" => 201,
        "msg" => "未收到上传文件！"
    );
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
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
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// 允许上传的文件大小（30MB，图网图床限制）
if ($_FILES["file"]["size"] > 30 * 1024 * 1024) {
    $result = array(
        "code" => 201,
        "msg" => "文件大小超出限制！图网图床最大只能上传30MB的文件！"
    );
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
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

// 图网图床 API 上传接口
$endpoint = 'https://www.imgw.cc/api/v1/upload';

// 构建请求头（Bearer Token 认证）
$headers = [
    'Authorization: Bearer ' . $token,
    'Accept: application/json',
];

// 构建请求参数（字段名是 file）
$postData = ['file' => new CURLFile($filepath, $fileType, random_name(12).'.'.$extension)];

// 将文件上传至图网图床
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
        "msg" => "连接图网图床失败：" . ($curlErr ?: '空响应') . " [HTTP " . $httpCode . "]"
    );
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// 解析响应
$imgData = json_decode($uploadimg, true);

if (!is_array($imgData)) {
    $snippet = mb_substr(trim(strip_tags($uploadimg)), 0, 300);
    $result = array(
        "code" => 201,
        "msg" => "图网图床接口返回了非JSON响应 [HTTP " . $httpCode . "]" . ($snippet ? "：" . $snippet : ""),
        "debug_response" => $snippet
    );
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// 图网图床返回格式：{"status":true,"message":"...","data":{"key":"...","links":{"url":"...",...}}}
$success = isset($imgData['status']) && $imgData['status'] === true;

if ($success && isset($imgData['data'])) {
    // 提取 data.links.url
    $imgUrl = '';
    if (isset($imgData['data']['links']['url'])) {
        $imgUrl = $imgData['data']['links']['url'];
    } elseif (isset($imgData['data']['url'])) {
        $imgUrl = $imgData['data']['url'];
    }

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
            "msg" => "图网图床返回成功但未获取到图片URL",
            "debug" => $uploadimg
        );
    }
} elseif ($httpCode === 401) {
    $result = array(
        "code" => 201,
        "msg" => "图网图床认证失败，请检查后台配置的 Token 是否正确！"
    );
} elseif ($httpCode === 403) {
    $result = array(
        "code" => 201,
        "msg" => "图网图床接口已被关闭或权限不足（403）"
    );
} elseif ($httpCode === 429) {
    $result = array(
        "code" => 201,
        "msg" => "图网图床请求配额已用尽，请稍后再试（429）"
    );
} else {
    $errorMsg = isset($imgData['message']) ? $imgData['message'] : '未知错误';
    $result = array(
        "code" => 201,
        "msg" => "上传失败 [HTTP " . $httpCode . "]：" . $errorMsg,
        "debug" => $uploadimg
    );
}

// 输出JSON
echo json_encode($result, JSON_UNESCAPED_UNICODE);

?>
