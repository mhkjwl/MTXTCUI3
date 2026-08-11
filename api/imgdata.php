<?php
/**
 * @file api/imgdata.php
 * @description ImgURL图床上传适配器
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

if(!defined("UPLOAD_GATE")){header("HTTP/1.1 403 Forbidden");exit("Forbidden");}
require ('../inc/lang.php');
require ('../inc/common.php');
header("Content-type:application/json");

$cookie = trim($conf['api_imgdata_cookie'] ?? '');
if (empty($cookie)) {
    echo json_encode(["code" => 201, "msg" => "请先在后台API接口设置中填写ImgURL图床的 Cookie 后再上传图片！"]); exit;
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

$endpoint = 'https://www.imgdata.cn/vip/upload/upload';

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $endpoint,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'file' => new CURLFile($filepath, $fileType, random_name(12).'.'.$extension),
        'comp' => 'yes',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_SSL_VERIFYPEER => is_file(dirname(__DIR__)."/inc/cacert.pem"),
    CURLOPT_SSL_VERIFYHOST => is_file(dirname(__DIR__)."/inc/cacert.pem") ? 2 : 0,
    CURLOPT_CAINFO => dirname(__DIR__)."/inc/cacert.pem",
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    CURLOPT_HTTPHEADER => [
        'Cookie: ' . $cookie,
        'Origin: https://www.imgdata.cn',
        'Referer: https://www.imgdata.cn/vip/manage/upload',
        'Accept: */*',
    ],
]);

$uploadimg = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
unset($ch);
@unlink($filepath);

if ($uploadimg === false || $uploadimg === '') {
    echo json_encode(["code" => 201, "msg" => "连接ImgURL图床失败：" . ($curlErr ?: '空响应') . " [HTTP " . $httpCode . "]"]); exit;
}

$imgData = json_decode($uploadimg, true);

if (!is_array($imgData)) {
    $snippet = mb_substr(trim(strip_tags($uploadimg)), 0, 300);
    echo json_encode([
        "code" => 201,
        "msg" => "ImgURL图床返回非JSON [HTTP " . $httpCode . "]" . ($snippet ? "：" . $snippet : ""),
    ], JSON_UNESCAPED_UNICODE); exit;
}

$code = isset($imgData['code']) ? (int)$imgData['code'] : 0;
$imgUrl = $imgData['data']['url'] ?? '';

if ($code === 200 && $imgUrl !== '' && filter_var($imgUrl, FILTER_VALIDATE_URL)) {
    echo json_encode([
        "code" => 200,
        "msg" => "上传成功！",
        "path" => $imgUrl,
    ], JSON_UNESCAPED_UNICODE);
} elseif ($httpCode === 401 || $code === 401) {
    $errorMsg = $imgData['msg'] ?? 'Cookie 无效';
    echo json_encode(["code" => 201, "msg" => "ImgURL图床未登录（401）：" . $errorMsg]);
} elseif ($httpCode === 403 || $code === 403) {
    echo json_encode(["code" => 201, "msg" => "ImgURL图床权限不足（403），请检查Cookie是否有效。"]);
} elseif ($httpCode === 413) {
    echo json_encode(["code" => 201, "msg" => "文件大小超过ImgURL图床限制。"]);
} else {
    $errorMsg = $imgData['msg'] ?? '未知错误';
    echo json_encode([
        "code" => 201,
        "msg" => "上传失败（HTTP " . $httpCode . "）：" . $errorMsg,
        "debug" => $uploadimg,
    ], JSON_UNESCAPED_UNICODE);
}

?>
