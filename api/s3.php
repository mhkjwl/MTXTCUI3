<?php
/**
 * @file api/s3.php
 * @description S3对象存储直接上传适配器（AWS Signature V4 PUT Object）
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

if(!defined("UPLOAD_GATE")){header("HTTP/1.1 403 Forbidden");exit("Forbidden");}
require ('../inc/lang.php');
require ('../inc/common.php');
header("Content-type:application/json");

// ========== S3 直接上传（AWS Signature V4 PUT Object） ==========
// POST 字段：s3_id=数字索引（对应 s3_storage_configs 数组的原始下标，含禁用配置）
//           file=图片文件

// 1. 加载 S3 配置（保留原始索引，与前端 index.php 的 $s3Configs 编号方式一致）
//    注意：前端和此处都用原始数组索引（不重新编号），确保 s3_id 对应正确
$s3ConfigsRaw = array();
if(isset($conf['s3_storage_configs']) && !empty($conf['s3_storage_configs'])) {
    $decoded = json_decode($conf['s3_storage_configs'], true);
    if(is_array($decoded)) {
        foreach($decoded as &$s3cfg) {
            if(isset($s3cfg['secret_key'])) {
                $s3cfg['secret_key'] = ct_decrypt($s3cfg['secret_key']);
            }
        }
        unset($s3cfg);
        $s3ConfigsRaw = $decoded;
    }
}

if(empty($s3ConfigsRaw)) {
    echo json_encode(["code" => 201, "msg" => "未配置 S3 存储，请在后台 S3 存储设置中添加配置后重试！"]);
    exit;
}

$s3Id = isset($_POST['s3_id']) ? (int)$_POST['s3_id'] : -1;
if($s3Id < 0 || !isset($s3ConfigsRaw[$s3Id])) {
    echo json_encode(["code" => 201, "msg" => "S3 配置不存在或参数无效！"]);
    exit;
}

$cfg = $s3ConfigsRaw[$s3Id];

// 检查是否启用
if(!isset($cfg['enabled']) || $cfg['enabled'] !== '1') {
    echo json_encode(["code" => 201, "msg" => "该 S3 存储已被禁用！"]);
    exit;
}

// 2. 校验文件
$file = isset($_FILES["file"]["name"]) ? $_FILES["file"]["name"] : '';
$tmpName = isset($_FILES["file"]["tmp_name"]) ? $_FILES["file"]["tmp_name"] : '';
if(empty($file) || empty($tmpName) || !is_uploaded_file($tmpName)) {
    echo json_encode(array("code" => 201, "msg" => "未收到上传文件！"));
    exit;
}

// 允许的图片真实类型
$allowedImage = array(
    IMAGETYPE_GIF  => 'gif',
    IMAGETYPE_JPEG => 'jpg',
    IMAGETYPE_PNG  => 'png',
    IMAGETYPE_WEBP => 'webp',
    IMAGETYPE_BMP  => 'bmp',
);

$imageInfo = @getimagesize($tmpName);
if($imageInfo === false || !isset($imageInfo[2]) || !isset($allowedImage[$imageInfo[2]])) {
    echo json_encode(array("code" => 201, "msg" => "只允许上传gif、jpeg、jpg、png、webp、bmp格式的真实图片文件！"));
    exit;
}

$safeExt = $allowedImage[$imageInfo[2]];

// 文件大小限制
$maxSizeMB = floatval($cfg['max_size'] ?? '10');
if($maxSizeMB <= 0) $maxSizeMB = 10;
$maxBytes = (int)($maxSizeMB * 1024 * 1024);
if($_FILES["file"]["size"] > $maxBytes) {
    echo json_encode(array("code" => 201, "msg" => "文件大小超出限制！最大只能上传" . $maxSizeMB . "MB的文件！"));
    exit;
}

// 3. 生成 S3 对象 Key
// 不使用日期子目录：未配置存储目录时直接传到 Bucket 根目录，配置后顺延存入该目录
$pathPrefix = isset($cfg['path_prefix']) && $cfg['path_prefix'] !== '' ? trim($cfg['path_prefix'], '/') . '/' : '';
$objectKey = $pathPrefix . uniqid() . '.' . $safeExt;

// 4. 构建 S3 请求
$endpoint = rtrim($cfg['endpoint'], '/');
// 确保 endpoint 带 scheme，否则 parse_url 会解析失败
if(strpos($endpoint, '://') === false) {
    $endpoint = 'https://' . $endpoint;
}
$bucket   = $cfg['bucket'];
$region   = $cfg['region'] ?: 'us-east-1';
$accessKey = $cfg['access_key'];
$secretKey = $cfg['secret_key'];
$usePathStyle = ($cfg['path_style'] ?? '0') === '1';
// SSL 证书校验开关：默认校验（'1'），老配置无此键时兜底为 '1'
$verifySsl = ($cfg['verify_ssl'] ?? '1') === '1';

// 服务别名 → 签名兼容
$serviceName = 's3';

// 构建 Host 和 URL
$parsedEndpoint = parse_url($endpoint);
if(!isset($parsedEndpoint['host']) || !$parsedEndpoint['host']) {
    echo json_encode(["code" => 201, "msg" => "Endpoint 格式错误，无法解析主机名，请填写完整地址（如 https://s3.amazonaws.com）"]);
    exit;
}
$isHttps = (isset($parsedEndpoint['scheme']) && $parsedEndpoint['scheme'] === 'https') ? 'https' : 'http';

// 对 object key 按 SigV4 规范做 URI 编码：每段单独 rawurlencode，保留 / 分隔符
// 注意：S3 canonical URI 不做二次编码，一次编码后同时用于签名与请求 URL，保持一致
$encodedObjectKey = implode('/', array_map('rawurlencode', explode('/', $objectKey)));
$encodedBucket = rawurlencode($bucket);

if($usePathStyle) {
    // path-style：实际请求路径为 /{bucket}/{key}，canonical URI 必须与之完全一致
    $host = $parsedEndpoint['host'] . (isset($parsedEndpoint['port']) ? ':' . $parsedEndpoint['port'] : '');
    $canonicalUri = '/' . $encodedBucket . '/' . $encodedObjectKey;
    $url  = $endpoint . '/' . $encodedBucket . '/' . $encodedObjectKey;
} else {
    // virtual-hosted-style：bucket 在 Host，实际请求路径为 /{key}
    $host = $bucket . '.' . $parsedEndpoint['host'] . (isset($parsedEndpoint['port']) ? ':' . $parsedEndpoint['port'] : '');
    $canonicalUri = '/' . $encodedObjectKey;
    $url = $isHttps . '://' . $host . '/' . $encodedObjectKey;
}

// 读取文件内容
$payload = file_get_contents($tmpName);
$fileSize = strlen($payload);
$contentType = $imageInfo['mime'];
// Content-MD5：对 payload 计算 MD5 的二进制值再 base64 编码
$contentMd5 = base64_encode(md5($payload, true));

// 5. AWS Signature V4
function s3_sign($key, $date, $region, $service) {
    $kDate   = hash_hmac('sha256', $date, 'AWS4' . $key, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService= hash_hmac('sha256', $service, $kRegion, true);
    $kSigning= hash_hmac('sha256', 'aws4_request', $kService, true);
    return $kSigning;
}

$now = time();
$amzDate = gmdate('Ymd\THis\Z', $now);
$dateStamp = gmdate('Ymd', $now);

// Canonical Request（$canonicalUri 已在上方按 path/virtual-hosted 风格构建）
$canonicalQueryString = '';
// 签名头按字母序排列：content-md5 < content-type < host < x-amz-content-sha256 < x-amz-date
$canonicalHeaders = "content-md5:" . $contentMd5 . "\n" .
                    "content-type:" . $contentType . "\n" .
                    "host:" . $host . "\n" .
                    "x-amz-content-sha256:" . hash('sha256', $payload) . "\n" .
                    "x-amz-date:" . $amzDate . "\n";
$signedHeaders = 'content-md5;content-type;host;x-amz-content-sha256;x-amz-date';

$canonicalRequest = "PUT\n" .
                    $canonicalUri . "\n" .
                    $canonicalQueryString . "\n" .
                    $canonicalHeaders . "\n" .
                    $signedHeaders . "\n" .
                    hash('sha256', $payload);

// String to Sign
$algorithm = 'AWS4-HMAC-SHA256';
$credentialScope = $dateStamp . '/' . $region . '/' . $serviceName . '/aws4_request';
$stringToSign = $algorithm . "\n" .
                $amzDate . "\n" .
                $credentialScope . "\n" .
                hash('sha256', $canonicalRequest);

// Signature
$signingKey = s3_sign($secretKey, $dateStamp, $region, $serviceName);
$signature = hash_hmac('sha256', $stringToSign, $signingKey);

// Authorization Header
$authorization = $algorithm . ' Credential=' . $accessKey . '/' . $credentialScope .
                 ', SignedHeaders=' . $signedHeaders .
                 ', Signature=' . $signature;

// 6. 发送 PUT 请求
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_CUSTOMREQUEST  => 'PUT',
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 30,
    CURLOPT_TIMEOUT        => 120,
    CURLOPT_SSL_VERIFYPEER => $verifySsl,
    CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    CURLOPT_HTTPHEADER     => [
        'Content-Type: ' . $contentType,
        'Content-Length: ' . $fileSize,
        'Content-MD5: ' . $contentMd5,
        'Host: ' . $host,
        'x-amz-content-sha256: ' . hash('sha256', $payload),
        'x-amz-date: ' . $amzDate,
        'Authorization: ' . $authorization,
    ],
    CURLOPT_HEADER         => true,
]);

$response = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

if($httpCode === 0 && $response === false) {
    unset($ch);
    echo json_encode(["code" => 201, "msg" => "连接 S3 服务失败：" . ($curlErr ?: '未知错误')]);
    exit;
}

// 分离响应头和体
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$headersRaw = substr($response, 0, $headerSize);
$body = substr($response, $headerSize);
unset($ch);

// 7. 处理结果
// 2xx 为成功
if($httpCode >= 200 && $httpCode < 300) {
    // 构建访问 URL
    $customDomain = isset($cfg['domain']) && $cfg['domain'] !== '' ? rtrim($cfg['domain'], '/') : '';
    if($customDomain) {
        $accessUrl = $customDomain . '/' . $objectKey;
    } elseif($usePathStyle) {
        $accessUrl = $endpoint . '/' . $bucket . '/' . $objectKey;
    } else {
        $parsedEndpoint = parse_url($endpoint);
        $isHttps = (isset($parsedEndpoint['scheme']) && $parsedEndpoint['scheme'] === 'https') ? 'https' : 'http';
        $accessUrl = $isHttps . '://' . $bucket . '.' . $parsedEndpoint['host'];
        if(isset($parsedEndpoint['port'])) $accessUrl .= ':' . $parsedEndpoint['port'];
        $accessUrl .= '/' . $objectKey;
    }

    echo json_encode([
        "code" => 200,
        "msg"  => "上传成功！",
        "path" => $accessUrl,
    ]);
} else {
    // 解析 XML 错误（S3 返回的错误是 XML 格式）
    $errMsg = 'S3 上传失败，HTTP ' . $httpCode;
    if($body) {
        // 尝试提取 S3 错误信息
        if(preg_match('/<Message>(.*?)<\/Message>/', $body, $m)) {
            $errMsg .= '：' . htmlspecialchars($m[1]);
        } else {
            // 可能与 S3 不兼容的错误消息
            $trimmed = trim(strip_tags($body));
            if(strlen($trimmed) > 0 && strlen($trimmed) < 300) {
                $errMsg .= '：' . $trimmed;
            }
        }
    }
    echo json_encode(["code" => 201, "msg" => $errMsg]);
    exit;
}
