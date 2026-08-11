<?php
/**
 * @file api/imgcc.php
 * @description 云图床上传适配器
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

if(!defined("UPLOAD_GATE")){header("HTTP/1.1 403 Forbidden");exit("Forbidden");}
require ('../inc/lang.php');
require ('../inc/common.php');
header("Content-type:application/json");

$apiKey = trim($conf['api_imgcc_key'] ?? '');
if (empty($apiKey)) {
    echo json_encode(["code" => 201, "msg" => "请先在后台API接口设置中填写云图床 API Key 后再上传图片！"]); exit;
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

$endpoint = 'https://imgcc.cloud/api/1/upload';

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $endpoint,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => ['source' => new CURLFile($filepath, $fileType, random_name(12).'.'.$extension)],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_SSL_VERIFYPEER => is_file(dirname(__DIR__)."/inc/cacert.pem"),
    CURLOPT_SSL_VERIFYHOST => is_file(dirname(__DIR__)."/inc/cacert.pem") ? 2 : 0,
    CURLOPT_CAINFO => dirname(__DIR__)."/inc/cacert.pem",
    CURLOPT_HTTPHEADER => [
        'X-API-Key: ' . $apiKey,
        'Accept: application/json',
    ],
]);

$uploadimg = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
unset($ch);
@unlink($filepath);

if ($uploadimg === false || $uploadimg === '') {
    echo json_encode(["code" => 201, "msg" => "连接云图床失败：" . ($curlErr ?: '空响应') . " [HTTP " . $httpCode . "]"]); exit;
}

$imgData = json_decode($uploadimg, true);

if (!is_array($imgData)) {
    $snippet = mb_substr(trim(strip_tags($uploadimg)), 0, 300);
    echo json_encode([
        "code" => 201,
        "msg" => "云图床返回非JSON [HTTP " . $httpCode . "]" . ($snippet ? "：" . $snippet : ""),
    ], JSON_UNESCAPED_UNICODE); exit;
}

$statusCode = isset($imgData['status_code']) ? (int)$imgData['status_code'] : 0;

if ($statusCode === 200) {
    $imgUrl = $imgData['image']['url'] ?? ($imgData['image']['display_url'] ?? '');

    if ($imgUrl !== '' && filter_var($imgUrl, FILTER_VALIDATE_URL)) {
        echo json_encode(["code" => 200, "msg" => "上传成功！", "path" => $imgUrl], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(["code" => 201, "msg" => "云图床返回成功但URL无效", "debug" => $uploadimg], JSON_UNESCAPED_UNICODE);
    }
} elseif ($statusCode === 400) {
    $errorMsg = $imgData['error']['message'] ?? ($imgData['status_txt'] ?? '参数错误');
    if (stripos($errorMsg, 'Duplicated') !== false || stripos($errorMsg, '重复') !== false) {
        $dupUrl = $imgData['image']['url'] ?? ($imgData['image']['display_url'] ?? '');
        if ($dupUrl !== '' && filter_var($dupUrl, FILTER_VALIDATE_URL)) {
            echo json_encode(["code" => 200, "msg" => "秒传成功！（图片已存在）", "path" => $dupUrl], JSON_UNESCAPED_UNICODE); exit;
        }
    }
    echo json_encode(["code" => 201, "msg" => "云图床参数错误（400）：" . $errorMsg]);
} elseif ($statusCode === 401 || $statusCode === 403) {
    echo json_encode(["code" => 201, "msg" => "云图床 API Key 鉴权失败，请检查后台配置的Key是否正确！"]);
} elseif ($httpCode === 413) {
    echo json_encode(["code" => 201, "msg" => "文件大小超过云图床限制。"]);
} else {
    $errorMsg = $imgData['status_txt'] ?? ($imgData['error']['message'] ?? '未知错误');
    echo json_encode(["code" => 201, "msg" => "上传失败（HTTP " . $httpCode . "）：" . $errorMsg, "debug" => $uploadimg], JSON_UNESCAPED_UNICODE);
}

?>
