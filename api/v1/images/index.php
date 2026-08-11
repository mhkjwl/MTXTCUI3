<?php
/**
 * @file api/v1/images/index.php
 * @description picui适配器v1图片管理接口（GET/HEAD/DELETE 查询与删除图片）
 * @author AI
 * @version 1.1.0-dev
 * @date 2026-08-04
 */
declare(strict_types=1);

require_once __DIR__ . '/../_bootstrap.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'HEAD', 'DELETE'], true)) {
    picui_failure('Method Not Allowed', 405, 405);
}

picui_require_auth(false);

$store = picui_load_store();
$key = trim((string)($_GET['key'] ?? ''));
if ($key === '' && !empty($_SERVER['PATH_INFO'])) {
    $segments = array_values(array_filter(explode('/', trim((string)$_SERVER['PATH_INFO'], '/'))));
    $key = $segments[0] ?? '';
}

if ($method === 'DELETE') {
    // IDOR 修复：文件型 store 不记录每张图片的归属用户，
    // 因此删除操作不能仅凭任意“已认证”身份放行。仅允许以下两类调用方删除：
    //   1) 已通过会话登录的管理员（picui_session_authenticated() 且 $GLOBALS['islogin'] == 1）
    //   2) 持有与配置 picui_token 匹配的有效 Bearer Token 的调用方
    // 普通前端用户（仅 isUserLoggedIn）不得通过此接口删除任意图片。
    $isAdminSession = picui_session_authenticated()
        && isset($GLOBALS['islogin'])
        && $GLOBALS['islogin'] == 1;
    $expectedToken = picui_expected_token();
    $providedToken = picui_bearer_token();
    $hasValidBearer = $expectedToken !== ''
        && $providedToken !== ''
        && hash_equals($expectedToken, $providedToken);
    if (!$isAdminSession && !$hasValidBearer) {
        picui_failure('无权删除图片：需要管理员会话或有效 API Token', 403, 403);
    }

    if ($key === '') {
        picui_failure('图片密钥不能为空', 400, 201);
    }
    picui_atomic_store(function(&$store) use ($key) {
        $index = picui_find_image_index($store['images'], $key);
        if ($index < 0) {
            return false;
        }
        $image = $store['images'][$index];
        $filePath = __DIR__ . '/../uploads/' . ltrim((string)($image['pathname'] ?? ''), '/');
        if (is_file($filePath)) {
            @unlink($filePath);
        }
        array_splice($store['images'], $index, 1);
        return true;
    });
    picui_json(true, '删除成功', [], 200, ['code' => 200, 'msg' => '删除成功']);
}

$page = max(1, (int)($_GET['page'] ?? 1));
$order = strtolower((string)($_GET['order'] ?? 'newest'));
$permission = (string)($_GET['permission'] ?? '');
$albumId = (int)($_GET['album_id'] ?? 0);
$q = trim((string)($_GET['q'] ?? ''));
$perPage = 10;

$images = $store['images'];
$images = array_values(array_filter($images, function ($row) use ($permission, $albumId, $q) {
    if ($permission !== '') {
        $value = picui_normalize_permission($row['permission'] ?? 1) === 1 ? 'public' : 'private';
        if ($value !== $permission) {
            return false;
        }
    }
    if ($albumId > 0 && (int)($row['album_id'] ?? 0) !== $albumId) {
        return false;
    }
    if ($q !== '') {
        $haystack = implode(' ', [
            (string)($row['name'] ?? ''),
            (string)($row['origin_name'] ?? ''),
            (string)($row['pathname'] ?? ''),
            (string)($row['md5'] ?? ''),
            (string)($row['sha1'] ?? ''),
        ]);
        if (mb_stripos($haystack, $q) === false) {
            return false;
        }
    }
    return true;
}));

$images = picui_filter_order($images, $order);
$total = count($images);
$lastPage = max(1, (int)ceil($total / $perPage));
$offset = ($page - 1) * $perPage;
$pageData = array_slice($images, $offset, $perPage);

$data = [];
foreach ($pageData as $row) {
    $data[] = picui_build_image_payload($row);
}

picui_json(true, 'ok', [
    'current_page' => $page,
    'last_page' => $lastPage,
    'per_page' => $perPage,
    'total' => $total,
    'data' => $data,
]);