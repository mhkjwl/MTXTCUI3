<?php
/**
 * @file api/yiyunt.php
 * @description 怡云图床上传适配器
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

// 允许上传的文件类型
$fileType = isset($_FILES["file"]["type"]) ? $_FILES["file"]["type"] : '';
$allowedTypes = ["image/gif", "image/jpeg", "image/jpg", "image/pjpeg", "image/x-png", "image/png", "image/webp", "image/bmp"];

if (!in_array($fileType, $allowedTypes)) {
    $result = array(
        "code" => 201,
        "msg" => "只允许上传gif、jpeg、jpg、png、webp、bmp格式的图片文件！"
    );
    echo json_encode($result);
    exit;
}

// 允许上传的文件大小（10MB）
if ($_FILES["file"]["size"] > 10 * 1024 * 1024) {
    $result = array(
        "code" => 201,
        "msg" => "文件大小超出限制！最大只能上传10MB的文件！"
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

// 怡云图床API接口地址 — 可选拼接Token
$endpoint = 'https://imgbed.yiyunt.cn/api/upload/';
$token = isset($conf['api_yiyunt_token']) ? trim($conf['api_yiyunt_token']) : '';
if ($token !== '') {
    $endpoint .= $token;
}

// 将文件上传至怡云图床（注意：字段名是 fileupload）
$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => $endpoint,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => ['fileupload' => new CURLFile($filepath, $fileType, random_name(12).'.'.$extension)],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 60,
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
        "msg" => "连接怡云图床失败：" . ($curlErr ?: '空响应') . " [HTTP " . $httpCode . "]"
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
        "msg" => "怡云图床接口返回了非JSON响应 [HTTP " . $httpCode . "]" . ($snippet ? "：" . $snippet : ""),
        "debug_url" => $endpoint,
        "debug_response" => $snippet
    );
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// 怡云图床响应格式：{"success":true,"url":"..."} 或 {"success":false,"error":"..."}
$success = isset($imgData['success']) && $imgData['success'] === true;

if ($success) {
    $imgUrl = isset($imgData['url']) ? $imgData['url'] : '';

    if ($imgUrl !== '' && filter_var($imgUrl, FILTER_VALIDATE_URL)) {
        $result = array(
            "code" => 200,
            "msg" => "上传成功！",
            "path" => $imgUrl
        );
    } else {
        $result = array(
            "code" => 201,
            "msg" => "怡云图床返回成功但未获取到图片URL",
            "debug" => $uploadimg
        );
    }
} else {
    $errorMsg = isset($imgData['error']) ? $imgData['error'] : (isset($imgData['msg']) ? $imgData['msg'] : '未知错误');
    $result = array(
        "code" => 201,
        "msg" => "上传失败：" . $errorMsg,
        "debug" => $uploadimg
    );
}

// 输出JSON
echo json_encode($result, JSON_UNESCAPED_UNICODE);

?>
