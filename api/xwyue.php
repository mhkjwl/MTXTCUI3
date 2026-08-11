<?php
/**
 * @file api/xwyue.php
 * @description 星跃图床上传适配器（https://img.xwyue.com/），无需登录即可上传
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
    $result = array(
        "code" => 201,
        "msg" => "未收到上传文件！"
    );
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// 获取文件后缀名
$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

// 允许上传的文件类型
$fileType = isset($_FILES["file"]["type"]) ? $_FILES["file"]["type"] : '';
$allowedTypes = ["image/gif", "image/jpeg", "image/jpg", "image/pjpeg", "image/x-png", "image/png", "image/webp", "image/bmp", "image/tiff", "image/x-tiff"];

if (!in_array($fileType, $allowedTypes)) {
    $result = array(
        "code" => 201,
        "msg" => "只允许上传gif、jpeg、jpg、png、webp、bmp、tiff格式的图片文件！"
    );
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// 允许上传的文件大小（20MB，星跃图床限制）
if ($_FILES["file"]["size"] > 20 * 1024 * 1024) {
    $result = array(
        "code" => 201,
        "msg" => "文件大小超出限制！星跃图床最大只能上传20MB的文件！"
    );
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
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

// 星跃图床 API 上传接口（无需登录，表单上传）
$endpoint = 'https://img.xwyue.com/api/v2/upload';

// 构建请求头
$headers = [
    'Accept: application/json, text/plain, */*',
    'Origin: https://img.xwyue.com',
    'Referer: https://img.xwyue.com/',
];

// 构建请求参数（字段名是 file，附带 storage_id 和 is_public）
$postData = [
    'file' => new CURLFile($filepath, $fileType, random_name(12).'.'.$extension),
    'storage_id' => '1',
    'is_public' => '0',
];

// 将文件上传至星跃图床
$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => $endpoint,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postData,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 90,
    CURLOPT_HTTPHEADER => $headers,
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
        "msg" => "连接星跃图床失败：" . ($curlErr ?: '空响应') . " [HTTP " . $httpCode . "]"
    );
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// 解析响应
$imgData = json_decode($uploadimg, true);

if (!is_array($imgData)) {
    $snippet = mb_substr(trim(strip_tags($uploadimg)), 0, 300);
    $result = array(
        "code" => 201,
        "msg" => "星跃图床接口返回了非JSON响应 [HTTP " . $httpCode . "]" . ($snippet ? "：" . $snippet : ""),
        "debug_response" => $snippet
    );
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// 星跃图床返回格式：{"status":"success","message":"successful","data":{"public_url":"..."}}
$success = isset($imgData['status']) && $imgData['status'] === 'success';

if ($success && isset($imgData['data'])) {
    // 提取 data.public_url
    $imgUrl = '';
    if (isset($imgData['data']['public_url'])) {
        $imgUrl = $imgData['data']['public_url'];
    } elseif (isset($imgData['data']['links']['url'])) {
        $imgUrl = $imgData['data']['links']['url'];
    } elseif (isset($imgData['data']['url'])) {
        $imgUrl = $imgData['data']['url'];
    }

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
            "msg" => "星跃图床返回成功但未获取到图片URL",
            "debug" => $uploadimg
        );
    }
} elseif ($httpCode === 401) {
    $result = array(
        "code" => 201,
        "msg" => "星跃图床认证失败（401）"
    );
} elseif ($httpCode === 403) {
    $result = array(
        "code" => 201,
        "msg" => "星跃图床接口已被关闭或权限不足（403）"
    );
} elseif ($httpCode === 429) {
    $result = array(
        "code" => 201,
        "msg" => "星跃图床请求配额已用尽，请稍后再试（429）"
    );
} else {
    $errorMsg = isset($imgData['message']) ? $imgData['message'] : '未知错误';
    $result = array(
        "code" => 201,
        "msg" => "上传失败 [HTTP " . $httpCode . "]：" . $errorMsg,
        "debug" => $uploadimg
    );
}

// 输出JSON
echo json_encode($result, JSON_UNESCAPED_UNICODE);

?>
