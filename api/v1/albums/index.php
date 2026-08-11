<?php
/**
 * @file api/v1/albums/index.php
 * @description picui适配器v1相册管理接口（GET/HEAD/DELETE 查询与删除相册）
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
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0 && !empty($_SERVER['PATH_INFO'])) {
    $segments = array_values(array_filter(explode('/', trim((string)$_SERVER['PATH_INFO'], '/'))));
    $id = (int)($segments[0] ?? 0);
}

if ($method === 'DELETE') {
    if ($id <= 0) {
        picui_failure('相册ID不能为空', 400, 201);
    }
    picui_atomic_store(function(&$store) use ($id) {
        $index = -1;
        foreach ($store['albums'] as $i => $album) {
            if ((int)($album['id'] ?? 0) === $id) {
                $index = $i;
                break;
            }
        }
        if ($index < 0) {
            return false;
        }
        array_splice($store['albums'], $index, 1);
        foreach ($store['images'] as &$image) {
            if ((int)($image['album_id'] ?? 0) === $id) {
                $image['album_id'] = 0;
            }
        }
        unset($image);
        return true;
    });
    picui_json(true, '删除成功', [], 200, ['code' => 200, 'msg' => '删除成功']);
}

$album = null;
foreach ($store['albums'] as $row) {
    if ((int)($row['id'] ?? 0) === $id) {
        $album = $row;
        break;
    }
}
if (!$album) {
    picui_failure('相册不存在', 404, 404);
}
$album['image_num'] = 0;
foreach ($store['images'] as $image) {
    if ((int)($image['album_id'] ?? 0) === $id) {
        $album['image_num']++;
    }
}

picui_json(true, 'ok', [
    'id' => (int)$album['id'],
    'name' => (string)$album['name'],
    'intro' => (string)($album['intro'] ?? ''),
    'image_num' => (int)($album['image_num'] ?? 0),
]);