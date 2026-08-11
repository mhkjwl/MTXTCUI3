<?php
/**
 * @file api/beeimg.php
 * @description 蜜蜂图床上传适配器（beeimg.cn）
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

if(!defined("UPLOAD_GATE")){header("HTTP/1.1 403 Forbidden");exit("Forbidden");}
require ('../inc/lang.php');
require ('../inc/common.php');
header("Content-type:application/json");

$file = $_FILES["file"]["name"] ?? '';
if (empty($file)) {
    echo json_encode(["code" => 201, "msg" => "未收到上传文件！"]); exit;
}

$apiKey = trim($conf['api_beeimg_key'] ?? '');

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

// 根据配置选择 V1 或 V2 接口
$apiVersion = $conf['api_beeimg_version'] ?? 'v2';
$endpoint = 'https://www.beeimg.cn/api/' . ($apiVersion === 'v1' ? 'v1' : 'v2') . '/upload';

$postData = ['file' => new CURLFile($filepath, $fileType, random_name(12).'.'.$extension)];

if ($apiVersion === 'v1') {
    // V1: permission (1=公开 0=私有), strategy_id, album_id, expired_at
    $isPublic = isset($conf['api_beeimg_public']) && $conf['api_beeimg_public'] == '1';
    $postData['permission'] = $isPublic ? '1' : '0';
} else {
    // V2: storage_id (必填), is_public, is_remove_exif, intro
    $postData['storage_id'] = isset($conf['api_beeimg_storage_id']) ? intval($conf['api_beeimg_storage_id']) : 1;
    // 文档说明：选中传'1'，未选中不传
    if (isset($conf['api_beeimg_public']) && $conf['api_beeimg_public'] == '1') {
        $postData['is_public'] = '1';
    }
    if (isset($conf['api_beeimg_remove_exif']) && $conf['api_beeimg_remove_exif'] == '1') {
        $postData['is_remove_exif'] = '1';
    }
}

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
    CURLOPT_HTTPHEADER => array_filter([
        'Accept: application/json',
        $apiKey ? 'authorization: Bearer ' . $apiKey : null,
    ]),
]);

$uploadimg = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
unset($ch);
@unlink($filepath);

if ($uploadimg === false || $uploadimg === '') {
    echo json_encode(["code" => 201, "msg" => "连接蜜蜂图床失败：" . ($curlErr ?: '空响应') . " [HTTP " . $httpCode . "]"]); exit;
}

$imgData = json_decode($uploadimg, true);

if (!is_array($imgData)) {
    $snippet = mb_substr(trim(strip_tags($uploadimg)), 0, 300);
    echo json_encode([
        "code" => 201,
        "msg"  => "蜜蜂图床返回非JSON [HTTP " . $httpCode . "]" . ($snippet ? "：" . $snippet : ""),
    ], JSON_UNESCAPED_UNICODE); exit;
}

// 文档判定：status === true 或 status === 'success'
$status = $imgData['status'] ?? null;
$isSuccess = ($status === true || $status === 'success') && $httpCode >= 200 && $httpCode < 300;

if ($isSuccess) {
    // 提取URL优先级：public_url > links.url > data.url > url > 扫描http字符串
    $d = $imgData['data'] ?? [];
    $imgUrl = $d['public_url'] ?? $d['links']['url'] ?? $d['url'] ?? $imgData['url'] ?? '';
    if ($imgUrl === '' && is_array($d)) {
        foreach ($d as $v) {
            if (is_string($v) && preg_match('#^https?://#', $v)) {
                $imgUrl = $v; break;
            }
        }
    }
    if ($imgUrl !== '' && filter_var($imgUrl, FILTER_VALIDATE_URL)) {
        echo json_encode(["code" => 200, "msg" => $imgData['message'] ?? '上传成功！', "path" => $imgUrl], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(["code" => 201, "msg" => "蜜蜂图床返回成功但URL无效", "debug" => $uploadimg], JSON_UNESCAPED_UNICODE);
    }
} elseif ($httpCode === 422) {
    $errors = [];
    if (isset($imgData['data']['errors'])) {
        foreach ($imgData['data']['errors'] as $field => $msgs) {
            $errors[] = $field . ': ' . implode(', ', (array)$msgs);
        }
    }
    echo json_encode(["code" => 201, "msg" => "参数错误：" . (implode('; ', $errors) ?: ($imgData['message'] ?? '未知'))], JSON_UNESCAPED_UNICODE);
} elseif ($httpCode === 401 || $httpCode === 403) {
    echo json_encode(["code" => 201, "msg" => "蜜蜂图床鉴权失败，请检查Token或Cookie"], JSON_UNESCAPED_UNICODE);
} elseif ($httpCode === 429) {
    echo json_encode(["code" => 201, "msg" => "蜜蜂图床请求频率超限，请稍后再试！"], JSON_UNESCAPED_UNICODE);
} else {
    // 错误提取优先级：message > msg > error
    $errMsg = $imgData['message'] ?? $imgData['msg'] ?? $imgData['error'] ?? '未知错误';
    echo json_encode(["code" => 201, "msg" => "上传失败（HTTP " . $httpCode . "）：" . $errMsg, "debug" => $uploadimg], JSON_UNESCAPED_UNICODE);
}

?>
