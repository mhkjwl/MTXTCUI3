<?php
/**
 * @file api/meituan.php
 * @description 美团创作接口图床上传适配器（ffapi.cn）
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

if(!defined("UPLOAD_GATE")){header("HTTP/1.1 403 Forbidden");exit("Forbidden");}
require ('../inc/lang.php');
require ('../inc/common.php');
header("Content-type:application/json");

$apiToken = trim($conf['api_meituan_token'] ?? '');
if ($apiToken === '') {
    echo json_encode(["code" => 201, "msg" => "请先在后台美团创作图床设置中填写Token后再上传图片！"]); exit;
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

$endpoint = 'https://ffapi.cn/int/v1/image.meituan';

$postData = [
    'file'  => new CURLFile($filepath, $fileType, random_name(12).'.'.$extension),
    'token' => $apiToken,
];

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $endpoint,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postData,
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
    echo json_encode(["code" => 201, "msg" => "连接美团创作接口失败：" . ($curlErr ?: '空响应') . " [HTTP " . $httpCode . "]"]); exit;
}

$imgData = json_decode($uploadimg, true);

if (!is_array($imgData)) {
    $snippet = mb_substr(trim(strip_tags($uploadimg)), 0, 300);
    echo json_encode([
        "code" => 201,
        "msg" => "美团创作接口返回非JSON响应 [HTTP " . $httpCode . "]" . ($snippet ? "：" . $snippet : ""),
        "debug_response" => $snippet,
    ], JSON_UNESCAPED_UNICODE); exit;
}

$code = (int)($imgData['code'] ?? 0);

if ($code === 200 && isset($imgData['url'])) {
    $imgUrl = $imgData['url'];
    if (filter_var($imgUrl, FILTER_VALIDATE_URL)) {
        echo json_encode([
            "code" => 200,
            "msg"  => $imgData['msg'] ?? '上传成功！',
            "path" => $imgUrl,
            "data" => $imgData['data'] ?? null,
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(["code" => 201, "msg" => "美团创作返回成功但URL无效", "debug" => $uploadimg], JSON_UNESCAPED_UNICODE);
    }
} else {
    $errMsg = $imgData['msg'] ?? '未知错误';
    echo json_encode(["code" => 201, "msg" => "上传失败（code " . $code . "）：" . $errMsg, "debug" => $uploadimg], JSON_UNESCAPED_UNICODE);
}

?>
