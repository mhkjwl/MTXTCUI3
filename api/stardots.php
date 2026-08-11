<?php
/**
 * @file api/stardots.php
 * @description StarDots图床上传适配器
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

// 检查 StarDots Key 是否已配置
$apiKey = isset($conf['api_stardots_key']) ? trim($conf['api_stardots_key']) : '';
if (empty($apiKey)) {
    $result = array("code" => 201, "msg" => "请先在后台API接口设置中填写StarDots Key后再上传图片！");
    echo json_encode($result); exit;
}

// 检查 StarDots Secret 是否已配置
$apiSecret = isset($conf['api_stardots_secret']) ? trim($conf['api_stardots_secret']) : '';
if (empty($apiSecret)) {
    $result = array("code" => 201, "msg" => "请先在后台API接口设置中填写StarDots Secret后再上传图片！");
    echo json_encode($result); exit;
}

// 检查空间名称是否已配置
$space = isset($conf['api_stardots_space']) ? trim($conf['api_stardots_space']) : '';
if (empty($space)) {
    $result = array("code" => 201, "msg" => "请先在后台API接口设置中填写StarDots空间名称后再上传图片！");
    echo json_encode($result); exit;
}

// 获取文件
$file = isset($_FILES["file"]["name"]) ? $_FILES["file"]["name"] : '';
if (empty($file)) {
    $result = array("code" => 201, "msg" => "未收到上传文件！");
    echo json_encode($result); exit;
}

// 获取文件后缀名
$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

// 允许上传的文件类型
$fileType = isset($_FILES["file"]["type"]) ? $_FILES["file"]["type"] : '';
$allowedTypes = ["image/gif", "image/jpeg", "image/jpg", "image/pjpeg", "image/x-png", "image/png", "image/webp", "image/bmp"];

if (!in_array($fileType, $allowedTypes)) {
    $result = array("code" => 201, "msg" => "只允许上传gif、jpeg、jpg、png、webp、bmp格式的图片文件！");
    echo json_encode($result); exit;
}

// 允许上传的文件大小（10MB）
if ($_FILES["file"]["size"] > 10 * 1024 * 1024) {
    $result = array("code" => 201, "msg" => "文件大小超出限制！最大只能上传10MB的文件！");
    echo json_encode($result); exit;
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

// ===== StarDots 签名鉴权 =====
// StarDots 签名鉴权（临时切换时区为 UTC+8，计算完后恢复）
$originalTz = date_default_timezone_get();
date_default_timezone_set('Asia/Singapore');
$timestamp = (string)time();

// 随机 nonce (4-20位字母数字)
$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
$nonce = '';
for ($i = 0; $i < 12; $i++) {
    $nonce .= $chars[random_int(0, strlen($chars) - 1)];
}

// 签名: MD5(timestamp|secret|nonce) 转大写
$signStr = $timestamp . '|' . $apiSecret . '|' . $nonce;
$sign = strtoupper(md5($signStr));
date_default_timezone_set($originalTz); // 恢复全局时区

// API 端点
$endpoint = 'https://api.stardots.io/openapi/file/upload';

// 构建 POST 数据
$postData = [
    'space' => $space,
    'file'  => new CURLFile($filepath, $fileType, random_name(12).'.'.$extension),
];

// 发起请求（注意：此接口使用 PUT 方法）
$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => $endpoint,
    CURLOPT_CUSTOMREQUEST => 'PUT',
    CURLOPT_POSTFIELDS => $postData,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_SSL_VERIFYPEER => is_file(dirname(__DIR__)."/inc/cacert.pem"),
    CURLOPT_SSL_VERIFYHOST => is_file(dirname(__DIR__)."/inc/cacert.pem") ? 2 : 0,
    CURLOPT_CAINFO => dirname(__DIR__)."/inc/cacert.pem",
    CURLOPT_HTTPHEADER => [
        'x-stardots-timestamp: ' . $timestamp,
        'x-stardots-nonce: ' . $nonce,
        'x-stardots-key: ' . $apiKey,
        'x-stardots-sign: ' . $sign,
        'Accept: application/json',
    ],
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
        "msg" => "连接StarDots图床失败：" . ($curlErr ?: '空响应') . " [HTTP " . $httpCode . "]"
    );
    echo json_encode($result); exit;
}

// 解析响应
$imgData = json_decode($uploadimg, true);

if (!is_array($imgData)) {
    $snippet = mb_substr(trim(strip_tags($uploadimg)), 0, 300);
    $result = array(
        "code" => 201,
        "msg" => "StarDots接口返回了非JSON响应 [HTTP " . $httpCode . "]" . ($snippet ? "：" . $snippet : ""),
        "debug_url" => $endpoint,
        "debug_response" => $snippet
    );
    echo json_encode($result, JSON_UNESCAPED_UNICODE); exit;
}

$code = isset($imgData['code']) ? (int)$imgData['code'] : -1;
$success = isset($imgData['success']) && $imgData['success'] === true;

if ($code === 200 && $success && isset($imgData['data']['url'])) {
    $imgUrl = $imgData['data']['url'];

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
            "msg" => "StarDots返回成功但未获取到图片URL",
            "debug" => $uploadimg
        );
    }
} else {
    // StarDots 错误码映射
    $errorMap = [
        504    => '请求超时',
        999999 => '服务器内部错误',
        100000 => '请求参数错误',
        100001 => '访问凭证失效，请检查Key/Secret',
        100009 => '空间不存在，请先在StarDots后台创建',
        100010 => '空间已存在',
        100011 => '空间不为空',
        100012 => '操作过于频繁',
        100013 => '空间数量已达上限',
        100014 => '文件数量已达上限（200张）',
        100015 => '流量配额已耗尽（每月10GB）',
        100018 => '文件超出大小限制（10MB）',
        100019 => '文件格式不受支持',
        100027 => 'API调用次数已达上限',
    ];
    $errorMsg = isset($errorMap[$code]) ? $errorMap[$code] : ($imgData['message'] ?? '未知错误');
    $result = array(
        "code" => 201,
        "msg" => "上传失败（code " . $code . "）：" . $errorMsg,
        "debug" => $uploadimg
    );
}

// 输出JSON
echo json_encode($result, JSON_UNESCAPED_UNICODE);

?>
