<?php
/**
 * @file api/youzan.php
 * @description 有赞图床上传适配器（两步上传，基于七牛云）
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

if(!defined("UPLOAD_GATE")){header("HTTP/1.1 403 Forbidden");exit("Forbidden");}
/**
 * 有赞图床 — 两步上传（基于七牛云）
 * 步骤1：POST 有赞接口获取 uptoken
 * 步骤2：POST uptoken + 图片到 upload.qiniup.com
 */
require ('../inc/lang.php');
require ('../inc/common.php');
header("Content-type:application/json");

// SID（会话ID）
$sid = isset($conf['api_youzan_sid']) ? trim($conf['api_youzan_sid']) : '';
if (empty($sid)) {
    echo json_encode(array("code" => 201, "msg" => "请先在后台API接口设置中填写有赞SID后再上传图片！"));
    exit;
}

// 获取文件
$file = isset($_FILES["file"]["name"]) ? $_FILES["file"]["name"] : '';
if (empty($file)) {
    echo json_encode(array("code" => 201, "msg" => "未收到上传文件！"));
    exit;
}

// 文件类型检查
$fileType = isset($_FILES["file"]["type"]) ? $_FILES["file"]["type"] : '';
$allowedTypes = ["image/gif", "image/jpeg", "image/jpg", "image/pjpeg", "image/x-png", "image/png", "image/webp", "image/bmp"];
if (!in_array($fileType, $allowedTypes)) {
    echo json_encode(array("code" => 201, "msg" => "只允许上传gif、jpeg、jpg、png、webp、bmp格式的图片文件！"));
    exit;
}

// 文件大小检查（10MB）
if ($_FILES["file"]["size"] > 10485760) {
    echo json_encode(array("code" => 201, "msg" => "文件大小超出限制！最大只能上传10MB的文件！"));
    exit;
}

$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$uploadDirectory = 'upload';
if (!is_dir($uploadDirectory)) {
    @mkdir($uploadDirectory, 0755, true);
}
$newfile = uniqid() . '.' . $extension;
move_uploaded_file($_FILES["file"]["tmp_name"], $uploadDirectory . "/" . $newfile);
$filepath = realpath(dirname(__FILE__)) . "/" . $uploadDirectory . "/" . $newfile;

// ===== 步骤1：POST 有赞接口获取 uptoken =====
$tokenUrl = 'https://www.youzan.com/v4/materials/api/shop/pubImgUploadToken.json';
$postBody = http_build_query(array(
    'channel'    => 'wsc_web',
    'csrf_token' => '',
));

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $tokenUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postBody);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setup_ssl($ch);
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    "Cookie: sid=" . $sid,
    "Origin: https://www.youzan.com",
    "Referer: https://www.youzan.com/v4/materials/attachment",
    "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
    "Accept: application/json, text/plain, */*"
));

$tokenResp = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
unset($ch);

if ($httpCode !== 200 || empty($tokenResp)) {
    @unlink($filepath);
    echo json_encode(array("code" => 201, "msg" => "获取上传凭证失败，SID可能已过期，HTTP " . $httpCode));
    exit;
}

$tokenData = json_decode($tokenResp, true);
if (!$tokenData || !isset($tokenData['code']) || $tokenData['code'] !== 0) {
    $msg = isset($tokenData['msg']) ? $tokenData['msg'] : '未知错误';
    @unlink($filepath);
    echo json_encode(array("code" => 201, "msg" => "获取上传凭证失败：" . $msg, "debug" => $tokenResp));
    exit;
}

$uploadToken = isset($tokenData['data']['uptoken']) ? $tokenData['data']['uptoken'] : '';
if (empty($uploadToken)) {
    @unlink($filepath);
    echo json_encode(array("code" => 201, "msg" => "获取上传凭证失败：返回的uptoken为空", "debug" => $tokenResp));
    exit;
}

// ===== 步骤2：上传图片至七牛云 =====
$qiniuUrl = 'https://upload.qiniup.com/';
$postData = array(
    'token'         => $uploadToken,
    'file'          => new CURLFile($filepath, '', $file),
    'x:categoryId'  => '',
);

$ch2 = curl_init();
curl_setopt($ch2, CURLOPT_URL, $qiniuUrl);
curl_setopt($ch2, CURLOPT_POST, true);
curl_setopt($ch2, CURLOPT_POSTFIELDS, $postData);
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setup_ssl($ch2);
curl_setopt($ch2, CURLOPT_HTTPHEADER, array(
    "Origin: https://www.youzan.com",
    "Referer: https://www.youzan.com/v4/materials/attachment",
    "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
    "Accept: application/json, text/plain, */*"
));

$uploadResp = curl_exec($ch2);
$uploadHttpCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
unset($ch2);
@unlink($filepath);

if ($uploadHttpCode !== 200 || empty($uploadResp)) {
    echo json_encode(array("code" => 201, "msg" => "上传至七牛云失败，HTTP " . $uploadHttpCode, "debug" => $uploadResp));
    exit;
}

$uploadData = json_decode($uploadResp, true);
if (!$uploadData || !isset($uploadData['code']) || $uploadData['code'] !== 0) {
    $msg = isset($uploadData['msg']) ? $uploadData['msg'] : '未知错误';
    echo json_encode(array("code" => 201, "msg" => "上传至七牛云失败：" . $msg, "debug" => $uploadResp));
    exit;
}

$imgUrl = isset($uploadData['data']['attachment_full_url']) ? $uploadData['data']['attachment_full_url']
        : (isset($uploadData['data']['attachment_url']) ? $uploadData['data']['attachment_url'] : '');

if (filter_var($imgUrl, FILTER_VALIDATE_URL)) {
    echo json_encode(array(
        "code" => 200,
        "msg"  => "上传成功！",
        "path" => $imgUrl
    ));
} else {
    echo json_encode(array(
        "code" => 201,
        "msg"  => "上传失败：未获取到图片链接",
        "debug" => $uploadResp
    ));
}
