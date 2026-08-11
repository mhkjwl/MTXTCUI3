<?php
/**
 * @file api/meipai.php
 * @description 美拍网图床上传适配器
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

if(!defined("UPLOAD_GATE")){header("HTTP/1.1 403 Forbidden");exit("Forbidden");}
require ('../inc/lang.php');
require ('../inc/common.php');
header("Content-type:application/json");

$apiToken = trim($conf['api_meipai_token'] ?? '');
if ($apiToken === '') {
    echo json_encode(["code" => 201, "msg" => "请先在后台美拍网图床设置中填写Token（Cookie中的__mapp_access_token__值）后再上传图片！"]); exit;
}

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

if ($_FILES["file"]["size"] > 10 * 1024 * 1024) {
    echo json_encode(["code" => 201, "msg" => "文件大小超出限制！最大只能上传10MB的文件！"]); exit;
}

$uploadDir = 'upload';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

$tmpName = uniqid() . '.' . $extension;
move_uploaded_file($_FILES["file"]["tmp_name"], $uploadDir . "/" . $tmpName);
$filepath = realpath(dirname(__FILE__)) . "/" . $uploadDir . "/" . $tmpName;

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'https://ffapi.cn/int/v1/image.meipai',
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'file'  => new CURLFile($filepath, $fileType, random_name(12).'.'.$extension),
        'token' => $apiToken,
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_SSL_VERIFYPEER => is_file(dirname(__DIR__)."/inc/cacert.pem"),
    CURLOPT_SSL_VERIFYHOST => is_file(dirname(__DIR__)."/inc/cacert.pem") ? 2 : 0,
    CURLOPT_CAINFO => dirname(__DIR__)."/inc/cacert.pem",
]);

$uploadimg = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
unset($ch);
@unlink($filepath);

if ($uploadimg === false || $uploadimg === '') {
    echo json_encode(["code" => 201, "msg" => "连接美拍网图床失败：" . ($curlErr ?: '空响应') . " [HTTP " . $httpCode . "]"]); exit;
}

$imgData = json_decode($uploadimg, true);

if (!is_array($imgData)) {
    $snippet = mb_substr(trim(strip_tags($uploadimg)), 0, 300);
    echo json_encode(["code" => 201, "msg" => "美拍网返回非JSON [HTTP " . $httpCode . "]" . ($snippet ? "：" . $snippet : "")], JSON_UNESCAPED_UNICODE); exit;
}

if (($imgData['code'] ?? 0) === 200 && !empty($imgData['url'])) {
    echo json_encode(["code" => 200, "msg" => $imgData['msg'] ?? '上传成功！', "path" => $imgData['url']], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(["code" => 201, "msg" => "上传失败：" . ($imgData['msg'] ?? '未知错误'), "debug" => $uploadimg], JSON_UNESCAPED_UNICODE);
}

?>
