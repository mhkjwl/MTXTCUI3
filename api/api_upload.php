<?php
declare(strict_types=1);
/**
 * 对外 API 上传接口（Bearer Token 鉴权）
 *
 * @file        api/api_upload.php
 * @description 第三方客户端通过 API 密钥上传图片
 *              鉴权方式：Header Authorization: Bearer sk-xxxxxxxxxxxxxxxx
 *              GET  请求：返回当前用户可用的图床接口列表（标识 + 名称 + 大小限制）
 *              POST 请求：上传图片
 *                - api=图床接口标识（字符串，如 cfbed / 360 / local）或 s3_id=数字
 *                - file=图片文件
 *              上传前检测图床接口开关状态，关闭则返回 403 "图床接口已关闭"
 *              向后兼容：api 参数仍支持旧版数字索引（自动映射为字符串标识）
 *              鉴权成功后将当前用户身份注入 $isUserLoggedIn/$currentUserId，
 *              复用前台套餐校验、配额校验、文件校验、图片记录逻辑
 *              安全：明文密钥不入库，DB 只存 SHA-256；密钥校验通过后更新 last_used_at
 * @author      eecms
 * @version     1.3.0-dev
 * @date        2026-08-04
 * @see         docs/AI开发规范.md § 5.4（API 数据传输 / Header 鉴权）
 */

require ('../inc/common.php');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

// ========== CORS（对外 API，允许跨域调用） ==========
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Max-Age: 86400');
if($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// 统一 JSON 输出（HTTP 状态码语义：200成功 400参数错误 401未授权 403禁止 413超限 500服务端错误）
function api_upload_json(int $code, string $msg, array $extra = []): void {
    http_response_code($code >= 400 && $code < 600 ? $code : 200);
    echo json_encode(array_merge(['code' => $code, 'msg' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

// 允许 POST（上传）和 GET（查询可用接口列表）
if($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_upload_json(405, '仅支持 POST / GET 请求');
}

// ========== Bearer 鉴权 ==========
$authHeader = '';
if(isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
} elseif(isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
} elseif(function_exists('getallheaders')) {
    $hdrs = getallheaders();
    foreach($hdrs as $k => $v) {
        if(strcasecmp($k, 'Authorization') === 0) { $authHeader = $v; break; }
    }
}
if(!preg_match('/^Bearer\s+(sk-[0-9a-f]{32})$/i', $authHeader, $m)) {
    http_response_code(401);
    api_upload_json(401, '无效的鉴权头，需 Authorization: Bearer sk-xxx');
}

$keyInfo = api_key_verify($DB, $m[1]);
if(!$keyInfo) {
    http_response_code(401);
    api_upload_json(401, 'API 密钥无效或已被禁用');
}
// 刷新最后使用时间（异步失败不阻断主流程）
api_key_touch($DB, (int)$keyInfo['id']);

// ========== 加载用户信息并注入全局（使下游套餐校验、图片记录逻辑一致） ==========
// U1 修复：原用 intval() 字符串拼接 SQL，违反 § 8.3.4，改用 get_row_prepared
$apiKeyUser = $DB->get_row_prepared('SELECT * FROM eecms_users WHERE id = ?', 'i', [(int)$keyInfo['user_id']]);
if(!$apiKeyUser || (int)$apiKeyUser['status'] !== 1) {
    http_response_code(403);
    api_upload_json(403, '密钥所属账户已被禁用或不存在');
}
$isUserLoggedIn  = true;
$currentUserId   = (int)$apiKeyUser['id'];
$currentUser     = $apiKeyUser;
$currentUserRole = $apiKeyUser['role'];

// ========== 图床接口配置（统一配置源，与 index.php / api/upload.php 共用 get_api_config()） ==========
// 每个图床接口的唯一标识即为此数组的 key（如 cfbed / 360 / local）
$apiConfig = get_api_config();

// ========== GET 请求：返回当前用户可用的图床接口列表 ==========
// API 用户可通过 GET /api/api_upload.php 查询可用接口标识，用于 POST 上传时传 api 参数
if($_SERVER['REQUEST_METHOD'] === 'GET') {
    $interfaces = [];
    // 普通图床接口
    foreach($apiConfig as $key => $info) {
        $enabled = is_api_enabled($conf, $key);
        $allowed = can_user_use_api($DB, (int)$currentUserId, $key, false, 0);
        if($enabled && $allowed) {
            $interfaces[] = [
                'id'       => $key,
                'name'     => $info['name'],
                'max_size' => (int)($info['max_size'] * 1048576),
            ];
        }
    }
    // S3 存储配置
    if(isset($conf['s3_storage_configs']) && $conf['s3_storage_configs'] !== '') {
        $s3Configs = json_decode($conf['s3_storage_configs'], true);
        if(is_array($s3Configs)) {
            foreach($s3Configs as $s3Idx => $s3Cfg) {
                if(isset($s3Cfg['enabled']) && $s3Cfg['enabled'] === '1') {
                    $s3Allowed = can_user_use_api($DB, (int)$currentUserId, 's3', true, (int)$s3Idx);
                    if($s3Allowed) {
                        $interfaces[] = [
                            'id'       => 's3_' . $s3Idx,
                            'name'     => isset($s3Cfg['name']) ? $s3Cfg['name'] : ('S3存储#' . $s3Idx),
                            'max_size' => isset($s3Cfg['max_size']) ? (int)$s3Cfg['max_size'] : 0,
                            's3_id'    => (int)$s3Idx,
                        ];
                    }
                }
            }
        }
    }
    api_upload_json(200, '获取成功', ['interfaces' => $interfaces]);
}

$s3Id = isset($_POST['s3_id']) ? $_POST['s3_id'] : null;

// 确定上传类型标识（用于图片记录 + 目标文件定位）
$uploadApiType = 'unknown';
if($s3Id !== null && ctype_digit($s3Id)) {
    $uploadApiType = 's3';
} else {
    $apiParam = $_POST['api'] ?? '';
    if($apiParam !== '') {
        // 优先接受字符串标识（如 cfbed / 360 / local）
        if(isset($apiConfig[$apiParam])) {
            $uploadApiType = $apiParam;
        }
        // 向后兼容：数字索引映射（旧客户端 api=0 → cfbed）
        elseif(ctype_digit($apiParam)) {
            $keys = array_keys($apiConfig);
            $idx = (int)$apiParam;
            if(isset($keys[$idx])) {
                $uploadApiType = $keys[$idx];
            }
        }
    }
}

// ========== 图床接口开关状态检测 ==========
// 上传前检查图床接口是否已开启，关闭则拒绝上传（§ 5.4.2 写操作需检测前置条件）
if($s3Id !== null && ctype_digit($s3Id)) {
    // S3 存储配置开关检测
    if(!is_s3_config_enabled($conf, (int)$s3Id)) {
        api_upload_json(403, '图床接口已关闭');
    }
} elseif($uploadApiType !== 'unknown') {
    // 普通图床接口开关检测
    if(!is_api_enabled($conf, $uploadApiType)) {
        api_upload_json(403, '图床接口已关闭');
    }
}

// ========== 套餐接口权限校验（服务端硬拦截） ==========
$isS3Upload = ($s3Id !== null && ctype_digit($s3Id));
$s3IdInt   = $isS3Upload ? (int)$s3Id : 0;
$checkApiType = $isS3Upload ? 's3' : $uploadApiType;
if(!can_user_use_api($DB, (int)$currentUserId, $checkApiType, $isS3Upload, $s3IdInt)) {
    api_upload_json(403, '当前套餐无权使用此上传接口');
}

// ========== 存储配额校验 ==========
$storageLimit = get_user_storage_limit($DB, (int)$currentUserId);
$storageUsed  = get_user_storage_used($DB, (int)$currentUserId);
$fileSize     = isset($_FILES['file']['size']) ? (int)$_FILES['file']['size'] : 0;
if($storageLimit > 0 && ($storageUsed + $fileSize) > $storageLimit) {
    $limitMB = round($storageLimit / 1048576, 1);
    $usedMB  = round($storageUsed / 1048576, 1);
    api_upload_json(413, "存储空间不足，配额 {$limitMB}MB，已用 {$usedMB}MB");
}

// 获取上传文件信息（用于图片记录）
$uploadFilename = '';
$uploadFileSize = 0;
if(isset($_FILES['file'])) {
    $uploadFilename = $_FILES['file']['name'];
    $uploadFileSize = (int)$_FILES['file']['size'];
}

// ========== 确定目标文件 ==========
$targetFile = null;
$apiKey = '';
if($s3Id !== null && ctype_digit($s3Id)) {
    $targetFile = __DIR__ . '/s3.php';
    if(!file_exists($targetFile)) {
        api_upload_json(400, 'S3 接口不存在');
    }
} else {
    if($uploadApiType === 'unknown') {
        api_upload_json(400, '参数无效：缺少图床接口标识（api 参数）');
    }
    $apiKey = $uploadApiType;
    $targetFile = __DIR__ . '/' . $apiKey . '.php';
    if(!file_exists($targetFile)) {
        api_upload_json(400, '接口不存在');
    }
}

// ========== 服务端文件校验（网关层统一防护，与 api/upload.php 一致） ==========
if(isset($_FILES['file']) && !empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
    $__isScdn = ($apiKey === 'scdn');
    $__imgMap = array(
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_WEBP => 'webp',
        IMAGETYPE_BMP  => 'bmp',
    );
    if($__isScdn) {
        $__imgMap[IMAGETYPE_TIFF_II] = 'tiff';
        $__imgMap[IMAGETYPE_TIFF_MM] = 'tiff';
    }
    $__imgInfo  = @getimagesize($_FILES['file']['tmp_name']);
    $__finalExt = null;
    if($__imgInfo !== false && isset($__imgInfo[2]) && isset($__imgMap[$__imgInfo[2]])) {
        $__finalExt = $__imgMap[$__imgInfo[2]];
    } elseif($__isScdn) {
        $__clientExt  = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        $__clientMime = isset($_FILES['file']['type']) ? $_FILES['file']['type'] : '';
        if(in_array($__clientExt, array('mp4','webm','mov','avi'), true)
            && in_array($__clientMime, array('video/mp4','video/webm','video/quicktime','video/x-msvideo'), true)) {
            $__finalExt = $__clientExt;
        }
    }
    if($__finalExt === null) {
        api_upload_json(400, '只允许上传真实的图片文件' . ($__isScdn ? '或 mp4/webm/mov/avi 视频' : '') . '！');
    }
    // 强制重写文件名：杜绝客户端伪造扩展名落地磁盘
    $_FILES['file']['name'] = random_name(10) . '.' . $__finalExt;
}

// ========== 输出缓冲 + 回调：记录上传结果 + 统一响应格式 ==========
// 使用 mysqli 预处理语句（遵循规范 § 8.3.4）
// 响应格式统一为：{"code":200,"msg":"上传成功","data":{"url":"...","filename":"..."}}
//                {"code":500,"msg":"上传失败：具体原因"}
$__urlRecorded = false;
$__recordCallback = function($buffer) use (&$__urlRecorded, $uploadFilename, $uploadFileSize, $uploadApiType) {
    if($__urlRecorded) return $buffer;
    $__urlRecorded = true;

    $uploadResult = json_decode($buffer, true);
    $isSuccess = false;
    $imageUrl  = '';
    $upstreamMsg = '';

    if($uploadResult && is_array($uploadResult)) {
        $isSuccess = (isset($uploadResult['status']) && $uploadResult['status'] === true) ||
                     (isset($uploadResult['code']) && (int)$uploadResult['code'] === 200);
        if(isset($uploadResult['path'])) {
            $imageUrl = $uploadResult['path'];
        } elseif(isset($uploadResult['data']['links']['url'])) {
            $imageUrl = $uploadResult['data']['links']['url'];
        } elseif(isset($uploadResult['url'])) {
            $imageUrl = $uploadResult['url'];
        }
        $upstreamMsg = isset($uploadResult['msg']) ? $uploadResult['msg'] :
                      (isset($uploadResult['message']) ? $uploadResult['message'] : '');
    }

    if($isSuccess && $imageUrl !== '') {
        // ===== 记录到 eecms_images =====
        global $DB, $currentUserId, $currentUser;

        $userId   = (int)$currentUserId;
        $username = isset($currentUser['username']) ? $currentUser['username'] : '';
        $thumbUrl = $imageUrl; // 缩略图暂用原图
        $ip       = real_ip();

        // § 8.3.4：使用预处理语句替代 escape() + 字符串拼接（与 api/upload.php 保持一致）
        $__imgOk = $DB->query_prepared(
            "INSERT INTO eecms_images (user_id, username, filename, url, thumb_url, size, api_type, ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            'issssiss',
            [$userId, $username, $uploadFilename, $imageUrl, $thumbUrl, $uploadFileSize, $uploadApiType, $ip]
        );
        if($__imgOk === false) {
            error_log('[api_upload] INSERT eecms_images failed: user=' . $userId . ' file=' . $uploadFilename);
        }

        // 更新用户上传计数
        if ($userId > 0) {
            $DB->query_prepared(
                "UPDATE eecms_users SET upload_count = upload_count + 1 WHERE id = ?",
                'i',
                [$userId]
            );
        }

        // ===== 同步全站累计统计（与 api/stats.php POST 逻辑一致）=====
        // U2 修复：移除每次上传都执行的 CREATE TABLE IF NOT EXISTS，依赖 stats.php/install 已建表
        $__todayDate  = date('Y-m-d');

        // 累计总成功数 +1（U3：改用 query_prepared，原字符串拼接违反 § 8.3.4）
        $DB->query_prepared(
            "INSERT INTO eecms_config SET `name`='upload_total_ok',`main`=? ON DUPLICATE KEY UPDATE `main` = CAST(`main` AS UNSIGNED) + ?",
            'ii',
            [1, 1]
        );

        // 今日成功数 +1（含跨天翻页处理）
        $__dateRow    = $DB->get_row("SELECT `main` FROM eecms_config WHERE `name`='upload_today_date'");
        $__storedDate = ($__dateRow && isset($__dateRow['main'])) ? $__dateRow['main'] : '';
        if ($__storedDate !== $__todayDate) {
            $DB->query_prepared(
                "INSERT INTO eecms_config SET `name`='upload_today_date',`main`=? ON DUPLICATE KEY UPDATE `main`=?",
                'ss',
                [$__todayDate, $__todayDate]
            );
            $DB->query_prepared(
                "INSERT INTO eecms_config SET `name`='upload_today_ok',`main`=? ON DUPLICATE KEY UPDATE `main`=?",
                'ii',
                [1, 1]
            );
        } else {
            $DB->query_prepared(
                "INSERT INTO eecms_config SET `name`='upload_today_ok',`main`=? ON DUPLICATE KEY UPDATE `main` = CAST(`main` AS UNSIGNED) + ?",
                'ii',
                [1, 1]
            );
        }

        // 每日累计统计 +1（趋势图数据源）
        $DB->query_prepared(
            "INSERT INTO eecms_upload_daily SET stat_date=?, upload_count=1 ON DUPLICATE KEY UPDATE upload_count = upload_count + 1",
            's',
            [$__todayDate]
        );

        // ===== 统一成功响应 =====
        return json_encode([
            'code' => 200,
            'msg'  => '上传成功',
            'data' => [
                'url'      => $imageUrl,
                'filename' => $uploadFilename,
                'size'     => $uploadFileSize,
                'api_type' => $uploadApiType,
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    // ===== 统一失败响应 =====
    // U4 修复：原失败路径不上报 fail 计数，导致 API 上传失败不计入 upload_total_fail/upload_today_fail
    //         前端路径通过 JS 调 stats.php 上报 fail，API 路径须在此对等补全
    global $DB;
    $__todayDate = date('Y-m-d');
    $DB->query_prepared(
        "INSERT INTO eecms_config SET `name`='upload_total_fail',`main`=? ON DUPLICATE KEY UPDATE `main` = CAST(`main` AS UNSIGNED) + ?",
        'ii',
        [1, 1]
    );
    $__dateRow    = $DB->get_row("SELECT `main` FROM eecms_config WHERE `name`='upload_today_date'");
    $__storedDate = ($__dateRow && isset($__dateRow['main'])) ? $__dateRow['main'] : '';
    if ($__storedDate !== $__todayDate) {
        $DB->query_prepared(
            "INSERT INTO eecms_config SET `name`='upload_today_date',`main`=? ON DUPLICATE KEY UPDATE `main`=?",
            'ss',
            [$__todayDate, $__todayDate]
        );
        $DB->query_prepared(
            "INSERT INTO eecms_config SET `name`='upload_today_fail',`main`=? ON DUPLICATE KEY UPDATE `main`=?",
            'ii',
            [1, 1]
        );
    } else {
        $DB->query_prepared(
            "INSERT INTO eecms_config SET `name`='upload_today_fail',`main`=? ON DUPLICATE KEY UPDATE `main` = CAST(`main` AS UNSIGNED) + ?",
            'ii',
            [1, 1]
        );
    }

    $failMsg = $upstreamMsg !== '' ? $upstreamMsg : '上传失败，请稍后重试';
    return json_encode([
        'code' => 500,
        'msg'  => '上传失败：' . $failMsg,
    ], JSON_UNESCAPED_UNICODE);
};

ob_start($__recordCallback);
define('UPLOAD_GATE', true);
chdir(__DIR__);
@include $targetFile;
ob_end_flush();
