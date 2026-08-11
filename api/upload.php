<?php
/**
 * @file api/upload.php
 * @description 统一上传中转入口，按数字索引映射到对应图床适配器
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

// ========== 统一上传中转入口 ==========
// 前端统一调用 /api/upload.php，POST 字段 api=数字索引 + file=图片文件
// 本文件根据数字索引映射到真实的 api/{key}.php，隐藏真实接口路径

require ('../inc/common.php');

// CSRF 校验：已登录用户（有 session）需验证 token；游客上传也需验证以防止跨站伪造
if(!csrf_verify()) {
    header("Content-type:application/json");
    echo json_encode(["code" => 201, "msg" => "安全校验失败，请刷新页面后重试"]);
    exit;
}

// 上传权限检查
$perm = check_upload_permission();
if(!$perm['allowed']) {
    header("Content-type:application/json");
    echo json_encode(["code" => 201, "msg" => $perm['msg']]);
    exit;
}

// 图床 key 白名单（统一配置源，与 index.php / api/api_upload.php 共用 get_api_config()）
$allowedApis = array_keys(get_api_config());

// S3 存储：前端同时传递 s3_id 参数
$s3Id = isset($_POST['s3_id']) ? $_POST['s3_id'] : null;

// 确定上传类型标签（用于图片记录）
$uploadApiType = 'unknown';
if ($s3Id !== null && ctype_digit($s3Id)) {
    $uploadApiType = 's3';
} else {
    $apiParam = $_POST['api'] ?? '';
    if ($apiParam !== '') {
        // 优先：直接接受字符串标识（如 cfbed / 360 / local）
        if (in_array($apiParam, $allowedApis, true)) {
            $uploadApiType = $apiParam;
        }
        // 向后兼容：数字索引映射（旧版前端发送 api=0 → cfbed）
        elseif (ctype_digit($apiParam) && isset($allowedApis[(int)$apiParam])) {
            $uploadApiType = $allowedApis[(int)$apiParam];
        }
    }
}

// ========== 接口启用状态校验（服务端硬拦截，尽早 fail）==========
// 校验顺序与 api/api_upload.php 一致：接口启用 → 套餐权限 → 配额
// 后台已关闭的接口（api_<key>_enable != 1 / S3 enabled != 1）一律禁止上传
// unknown 类型跳过此校验，由下方「确定目标文件」处拦截返回参数错误
if ($s3Id !== null && ctype_digit($s3Id)) {
    if (!is_s3_config_enabled($conf, (int)$s3Id)) {
        header("Content-type:application/json");
        echo json_encode(["code" => 201, "msg" => "该存储接口已被管理员关闭"]);
        exit;
    }
} elseif ($uploadApiType !== 'unknown') {
    if (!is_api_enabled($conf, $uploadApiType)) {
        header("Content-type:application/json");
        echo json_encode(["code" => 201, "msg" => "该上传接口已被管理员关闭"]);
        exit;
    }
}

// ========== 套餐接口权限校验（服务端硬拦截）==========
// 前端上传权限仅基于用户中心登录状态（$isUserLoggedIn），不使用管理员后台登录状态。
// 管理员在后台登录后通过前端上传时，仍以访客身份接受权限校验。
$pkgCheckUserId = 0;
if($isUserLoggedIn) {
    $pkgCheckUserId = (int)$currentUserId;
}

if($pkgCheckUserId >= 0) {
    $isS3Upload = ($s3Id !== null && ctype_digit($s3Id));
    $s3IdInt = $isS3Upload ? (int)$s3Id : 0;
    $checkApiType = $isS3Upload ? 's3' : $uploadApiType;
    if(!can_user_use_api($DB, $pkgCheckUserId, $checkApiType, $isS3Upload, $s3IdInt)) {
        header("Content-type:application/json");
        echo json_encode(["code" => 201, "msg" => "当前套餐无权使用此上传接口"]);
        exit;
    }
}

// ========== 存储配额校验（storage_limit == -1 表示无限制，跳过校验） ==========
if($pkgCheckUserId > 0) {
    $storageLimit = get_user_storage_limit($DB, $pkgCheckUserId);
    $storageUsed = get_user_storage_used($DB, $pkgCheckUserId);
    $fileSize = isset($_FILES['file']['size']) ? (int)$_FILES['file']['size'] : 0;
    // storageLimit > 0 时才校验，-1（无限制）或 0 时跳过
    if($storageLimit > 0 && ($storageUsed + $fileSize) > $storageLimit) {
        header("Content-type:application/json");
        $limitMB = round($storageLimit / 1048576, 1);
        $usedMB = round($storageUsed / 1048576, 1);
        echo json_encode(["code" => 201, "msg" => "存储空间不足，配额 {$limitMB}MB，已用 {$usedMB}MB"]);
        exit;
    }
}

// 获取上传文件信息（用于图片记录）
$uploadFilename = '';
$uploadFileSize = 0;
if(isset($_FILES['file'])) {
    $uploadFilename = $_FILES['file']['name'];
    $uploadFileSize = (int)$_FILES['file']['size'];
}

// 先确定目标文件（在开启缓冲前完成所有错误检查）
$targetFile = null;

if ($s3Id !== null && ctype_digit($s3Id)) {
    $targetFile = __DIR__ . '/s3.php';
    if (!file_exists($targetFile)) {
        header("Content-type:application/json");
        echo json_encode(["code" => 201, "msg" => "S3 接口不存在"]);
        exit;
    }
} else {
    if ($uploadApiType === 'unknown') {
        header("Content-type:application/json");
        echo json_encode(["code" => 201, "msg" => "参数无效：缺少有效的图床接口标识"]);
        exit;
    }
    $apiKey = $uploadApiType;
    $targetFile = __DIR__ . '/' . $apiKey . '.php';
    if (!file_exists($targetFile)) {
        header("Content-type:application/json");
        echo json_encode(["code" => 201, "msg" => "接口不存在"]);
        exit;
    }
}

// ========== 服务端文件校验（网关层统一防护，覆盖全部下游接口）==========
// 背景：多数第三方接口仅校验可伪造的客户端 MIME，且临时文件后缀取自客户端文件名，
// 可在 api/upload/ 落地 .php 等可执行文件。此处统一拦截：
// 1) 真实图片：getimagesize 服务端判定，强制重写为安全后缀（下游接口 pathinfo 取到的即是安全后缀）
// 2) scdn 接口例外：额外允许 TIFF 图片及 mp4/webm/mov/avi 视频（该接口服务端转码，MIME+扩展名双白名单）
if (isset($_FILES['file']) && !empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
    $__isScdn = (isset($apiKey) && $apiKey === 'scdn');
    $__imgMap = array(
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_WEBP => 'webp',
        IMAGETYPE_BMP  => 'bmp',
    );
    if ($__isScdn) {
        $__imgMap[IMAGETYPE_TIFF_II] = 'tiff';
        $__imgMap[IMAGETYPE_TIFF_MM] = 'tiff';
    }
    $__imgInfo  = @getimagesize($_FILES['file']['tmp_name']);
    $__finalExt = null;
    if ($__imgInfo !== false && isset($__imgInfo[2]) && isset($__imgMap[$__imgInfo[2]])) {
        $__finalExt = $__imgMap[$__imgInfo[2]];
    } elseif ($__isScdn) {
        $__clientExt  = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        $__clientMime = isset($_FILES['file']['type']) ? $_FILES['file']['type'] : '';
        if (in_array($__clientExt, array('mp4','webm','mov','avi'), true)
            && in_array($__clientMime, array('video/mp4','video/webm','video/quicktime','video/x-msvideo'), true)) {
            $__finalExt = $__clientExt;
        }
    }
    if ($__finalExt === null) {
        header("Content-type:application/json");
        echo json_encode(["code" => 201, "msg" => "只允许上传真实的图片文件" . ($__isScdn ? "或 mp4/webm/mov/avi 视频" : "") . "！"]);
        exit;
    }
    // 强制重写文件名：杜绝客户端伪造扩展名落地磁盘
    $_FILES['file']['name'] = random_name(10) . '.' . $__finalExt;
}

// ========== 关键修复：使用 ob_start 回调确保即使 API 文件调用 exit 也能记录 URL ==========
// 许多 API 文件（如 imgcc.php, naixiai.php 等）在 echo json_encode() 后调用 exit，
// 导致下方 ob_get_clean() 永远不会执行，图片 URL 无法记录到数据库。
// 使用 ob_start(callback) 后，无论 API 文件是否调用 exit，回调都会被触发。

$__urlRecorded = false;

$__recordCallback = function($buffer) use (&$__urlRecorded, $uploadFilename, $uploadFileSize, $uploadApiType) {
    if ($__urlRecorded) return $buffer;
    $__urlRecorded = true;

    // 解析 JSON 并记录到 eecms_images
    $uploadResult = json_decode($buffer, true);
    if ($uploadResult && is_array($uploadResult)) {
        $isSuccess = (isset($uploadResult['status']) && $uploadResult['status'] === true) ||
                     (isset($uploadResult['code']) && (int)$uploadResult['code'] === 200);
        $imageUrl = '';
        if (isset($uploadResult['path'])) {
            $imageUrl = $uploadResult['path'];
        } elseif (isset($uploadResult['data']['links']['url'])) {
            $imageUrl = $uploadResult['data']['links']['url'];
        } elseif (isset($uploadResult['url'])) {
            $imageUrl = $uploadResult['url'];
        }

        if ($isSuccess && $imageUrl !== '') {
            global $DB, $isUserLoggedIn, $currentUserId, $currentUser;

            // 防御：$DB 可能在某些上下文中为 null（如 DB 连接失败），跳过记录但不影响上传结果
            if ($DB === null) {
                error_log('[upload] $DB is null, cannot record image: file=' . $uploadFilename . ' url=' . $imageUrl);
                return $buffer;
            }

            $userId   = $isUserLoggedIn ? (int)$currentUserId : 0;
            $username = $isUserLoggedIn ? ($currentUser['username'] ?? '') : '';
            $ip       = real_ip();
            $thumbUrl = $imageUrl; // 缩略图暂时用原图

            // § 8.3.4：使用预处理语句替代 escape() + 字符串拼接
            $__imgOk = $DB->query_prepared(
                "INSERT INTO eecms_images (user_id, username, filename, url, thumb_url, size, api_type, ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                'issssiss',
                [$userId, $username, $uploadFilename, $imageUrl, $thumbUrl, $uploadFileSize, $uploadApiType, $ip]
            );
            if($__imgOk === false) {
                error_log('[upload] INSERT eecms_images failed: user=' . $userId . ' file=' . $uploadFilename);
            }

            // 更新用户上传计数（预处理语句）
            if ($userId > 0) {
                $DB->query_prepared(
                    "UPDATE eecms_users SET upload_count = upload_count + 1 WHERE id = ?",
                    'i',
                    [$userId]
                );
            }
        }
    }

    // 返回原始内容，不修改输出
    return $buffer;
};

// 开启输出缓冲（带回调）
ob_start($__recordCallback);

// 定义门禁常量
define('UPLOAD_GATE', true);
chdir(__DIR__);
@include $targetFile;

// 如果 API 文件没有调用 exit，手动刷新缓冲（触发回调）
ob_end_flush();
