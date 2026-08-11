<?php
/**
 * @file api/keye.php
 * @description 珂艺云图床上传适配器（https://tc.0147258.xyz/），无需登录即可上传
 *              使用 JWT (HS256) 鉴权，token 由 timestamp + HMAC-SHA256 生成
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
$allowedTypes = ["image/gif", "image/jpeg", "image/jpg", "image/pjpeg", "image/x-png", "image/png", "image/webp", "image/bmp", "image/tiff", "image/x-tiff"];

if (!in_array($fileType, $allowedTypes)) {
    echo json_encode(["code" => 201, "msg" => "只允许上传gif、jpeg、jpg、png、webp、bmp、tiff格式的图片文件！"], JSON_UNESCAPED_UNICODE);
    exit;
}

// 允许上传的文件大小（100MB，珂艺云图床限制）
if ($_FILES["file"]["size"] > 100 * 1024 * 1024) {
    echo json_encode(["code" => 201, "msg" => "文件大小超出限制！珂艺云图床最大只能上传100MB的文件！"], JSON_UNESCAPED_UNICODE);
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

// ========== 生成 JWT Token (HS256) ==========
// Header: {"alg":"HS256","typ":"JWT"}
// Payload: {"timestamp":<毫秒时间戳>}
// Secret: 9a31f2e82617e4b4b482110f8c928b9b2734d809f060c30f12e8b2574a84c122
$jwtHeader = json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_UNESCAPED_UNICODE);
$jwtPayload = json_encode(['timestamp' => (int)(microtime(true) * 1000)], JSON_UNESCAPED_UNICODE);

$jwtSecret = '9a31f2e82617e4b4b482110f8c928b9b2734d809f060c30f12e8b2574a84c122';

// base64url 编码
function keye_base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

$headerB64 = keye_base64url_encode($jwtHeader);
$payloadB64 = keye_base64url_encode($jwtPayload);
$signingInput = $headerB64 . '.' . $payloadB64;

// HMAC-SHA256 签名
$signature = hash_hmac('sha256', $signingInput, $jwtSecret, true);
$signatureB64 = keye_base64url_encode($signature);

$authToken = $headerB64 . '.' . $payloadB64 . '.' . $signatureB64;

// ========== 上传到珂艺云图床 ==========
$endpoint = 'https://tc.0147258.xyz/upload';

$headers = [
    'Accept: application/json, text/plain, */*',
    'X-Auth-Token: ' . $authToken,
    'Origin: https://tc.0147258.xyz',
    'Referer: https://tc.0147258.xyz/',
];

$postData = [
    'file' => new CURLFile($filepath, $fileType, random_name(12).'.'.$extension),
];

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $endpoint,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postData,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 120,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_SSL_VERIFYPEER => is_file(dirname(__DIR__)."/inc/cacert.pem"),
    CURLOPT_SSL_VERIFYHOST => is_file(dirname(__DIR__)."/inc/cacert.pem") ? 2 : 0,
    CURLOPT_CAINFO => dirname(__DIR__)."/inc/cacert.pem",
]);

$uploadimg = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
unset($ch);

// 删除临时文件
@unlink($filepath);

// 处理curl错误
if ($uploadimg === false || $uploadimg === '') {
    echo json_encode(["code" => 201, "msg" => "连接珂艺云图床失败：" . ($curlErr ?: '空响应') . " [HTTP " . $httpCode . "]"], JSON_UNESCAPED_UNICODE);
    exit;
}

// 解析响应
$imgData = json_decode($uploadimg, true);

if (!is_array($imgData)) {
    $snippet = mb_substr(trim(strip_tags($uploadimg)), 0, 300);
    echo json_encode(["code" => 201, "msg" => "珂艺云图床接口返回了非JSON响应 [HTTP " . $httpCode . "]" . ($snippet ? "：" . $snippet : ""), "debug_response" => $snippet], JSON_UNESCAPED_UNICODE);
    exit;
}

// 珂艺云图床返回格式：{"data":"https://xxx.com/path/to/image.png"}
if (isset($imgData['data']) && is_string($imgData['data']) && filter_var($imgData['data'], FILTER_VALIDATE_URL)) {
    echo json_encode(["code" => 200, "msg" => "上传成功！", "path" => $imgData['data']], JSON_UNESCAPED_UNICODE);
} elseif ($httpCode === 401) {
    echo json_encode(["code" => 201, "msg" => "珂艺云图床鉴权失败（401），JWT Token 无效"], JSON_UNESCAPED_UNICODE);
} elseif ($httpCode === 403) {
    echo json_encode(["code" => 201, "msg" => "珂艺云图床接口已被关闭或权限不足（403）"], JSON_UNESCAPED_UNICODE);
} elseif ($httpCode === 429) {
    echo json_encode(["code" => 201, "msg" => "珂艺云图床请求配额已用尽，请稍后再试（429）"], JSON_UNESCAPED_UNICODE);
} else {
    $errorMsg = isset($imgData['message']) ? $imgData['message'] : (isset($imgData['data']) && is_string($imgData['data']) ? $imgData['data'] : '未知错误');
    echo json_encode(["code" => 201, "msg" => "上传失败 [HTTP " . $httpCode . "]：" . $errorMsg, "debug" => $uploadimg], JSON_UNESCAPED_UNICODE);
}

?>
