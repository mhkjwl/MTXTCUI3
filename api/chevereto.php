<?php
/**
 * @file api/chevereto.php
 * @description PlcGO图床（Chevereto）上传适配器
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

// 检查PlcGO接口地址 — 未配置则自动使用当前站点地址
$apiUrl = 'https://www.picgo.net';

// 检查PlcGO API Key是否已配置
$apiKey = isset($conf['api_chevereto_key']) ? $conf['api_chevereto_key'] : '';
if(empty($apiKey)) {
    $result = array(
        "code" => 201,
        "msg" => "请先在后台API接口设置中填写PlcGO API Key后再上传图片！"
    );
    echo json_encode($result);
    exit;
}

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
if ($_FILES["file"]["size"] > 10485760) {
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

// 构建完整的API端点
$endpoint = $apiUrl . '/api/1/upload';

// 将文件上传至PlcGO图床
$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => $endpoint,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => ['source' => new CURLFile($filepath, '', $file)],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_SSL_VERIFYPEER => is_file(dirname(__DIR__)."/inc/cacert.pem"),
    CURLOPT_SSL_VERIFYHOST => is_file(dirname(__DIR__)."/inc/cacert.pem") ? 2 : 0,
    CURLOPT_CAINFO => dirname(__DIR__)."/inc/cacert.pem",
    CURLOPT_HTTPHEADER => [
        'X-API-Key: ' . $apiKey,
        'Accept: application/json'
    ],
]);

// 发起请求
$uploadimg = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
unset($ch);

// 删除临时文件
@unlink($filepath);

// 处理curl错误
if ($uploadimg === false || $uploadimg === '') {
    $result = array(
        "code" => 201,
        "msg" => "连接PlcGO图床失败：" . ($curlErr ?: '空响应') . " [HTTP " . $httpCode . "]"
    );
    echo json_encode($result);
    exit;
}

// 解析响应
$imgData = json_decode($uploadimg, true);

if (!is_array($imgData)) {
    // 非JSON响应 — 提供详细调试信息
    $snippet = mb_substr(trim(strip_tags($uploadimg)), 0, 300);
    $result = array(
        "code" => 201,
        "msg" => "PlcGO接口返回了非JSON响应 [HTTP " . $httpCode . "]" . ($snippet ? "：" . $snippet : ""),
        "debug_url" => $endpoint,
        "debug_response" => $snippet
    );
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

$statusCode = isset($imgData['status_code']) ? (int)$imgData['status_code'] : 0;
$statusTxt = isset($imgData['status_txt']) ? $imgData['status_txt'] : '';

if ($statusCode === 200) {
    // 上传成功 — 提取图片URL
    $imgUrl = '';
    if (isset($imgData['image']['url'])) {
        $imgUrl = $imgData['image']['url'];
    } elseif (isset($imgData['image']['display_url'])) {
        $imgUrl = $imgData['image']['display_url'];
    }

    if ($imgUrl !== '' && filter_var($imgUrl, FILTER_VALIDATE_URL)) {
        $result = array(
            "code" => 200,
            "msg" => "上传成功！",
            "path" => $imgUrl,
            "data" => $imgData['image']
        );
    } else {
        $result = array(
            "code" => 201,
            "msg" => "PlcGO返回成功但未获取到图片URL",
            "debug" => $uploadimg
        );
    }
} elseif ($statusCode === 400) {
    $errorMsg = isset($imgData['error']['message']) ? $imgData['error']['message'] : $statusTxt;
    // 重复上传：虽然 status_code 为 400，但图片已存在，API 仍会返回 image.url
    if (stripos($errorMsg, 'Duplicated') !== false || stripos($errorMsg, '重复') !== false) {
        $dupUrl = $imgData['image']['url'] ?? ($imgData['image']['display_url'] ?? '');
        if ($dupUrl !== '' && filter_var($dupUrl, FILTER_VALIDATE_URL)) {
            $result = array(
                "code" => 200,
                "msg" => "秒传成功！（图片已存在）",
                "path" => $dupUrl,
            );
        } else {
            $result = array(
                "code" => 201,
                "msg" => "PlcGO检测到重复上传，但未获取到已有图片URL",
                "debug" => $uploadimg
            );
        }
    } else {
        $result = array(
            "code" => 201,
            "msg" => "PlcGO参数错误：" . $errorMsg
        );
    }
} elseif ($statusCode === 401 || $statusCode === 403) {
    $result = array(
        "code" => 201,
        "msg" => "PlcGO API Key 鉴权失败，请检查后台配置的API Key是否正确！"
    );
} else {
    $result = array(
        "code" => 201,
        "msg" => "上传失败：" . ($statusTxt ?: '未知错误') . " (HTTP " . $httpCode . ", status_code " . $statusCode . ")",
        "debug" => $uploadimg
    );
}

// 输出JSON
echo json_encode($result, JSON_UNESCAPED_UNICODE);

?>
