<?php
declare(strict_types=1);
/**
 * 用户中心 API 密钥管理接口
 *
 * @file        api/api_keys.php
 * @description 用户端密钥 CRUD：list / create / regen / delete / toggle
 *              全部操作均要求用户已登录（$isUserLoggedIn）+ CSRF 校验
 *              明文密钥仅在 create / regen 时返回一次，DB 只存 SHA-256 哈希
 * @author      eecms
 * @version     1.1.0-dev
 * @date        2026-08-04
 * @see         docs/AI开发规范.md § 5.4（API 数据传输）、§ 8.3.4（预处理）
 */

require ('../inc/common.php');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// 统一 JSON 输出助手
function api_keys_json(int $code, string $msg, array $extra = []): void {
    echo json_encode(array_merge(['code' => $code, 'msg' => $msg], $extra));
    exit;
}

// ========== 鉴权：必须登录 ==========
if(!$isUserLoggedIn) {
    api_keys_json(401, '请先登录');
}

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
$uid    = (int)$currentUserId;

// ========== CSRF 校验：仅写操作校验（list 为查询，按 CSRF 规范 GET 不需校验） ==========
$writeActions = ['create', 'regen', 'delete', 'toggle'];
if(in_array($action, $writeActions, true) && !csrf_verify()) {
    api_keys_json(201, '安全校验失败，请刷新页面后重试');
}

switch($action) {

// ========== 获取当前用户密钥列表 ==========
case 'list':
    $rows = api_key_list_by_user($DB, $uid);
    $list = [];
    foreach($rows as $r) {
        $list[] = [
            'id'           => (int)$r['id'],
            'name'         => $r['name'],
            'key_prefix'   => $r['key_prefix'],
            'status'       => (int)$r['status'],
            'last_used_at' => $r['last_used_at'],
            'created_at'   => $r['created_at'],
        ];
    }
    api_keys_json(0, 'ok', ['data' => $list]);
    break;

// ========== 生成新密钥（明文仅返回一次） ==========
case 'create':
    $name = trim(isset($_POST['name']) ? $_POST['name'] : '');
    if($name === '') {
        api_keys_json(1, '请输入密钥名称');
    }
    if(strlen($name) > 100) {
        api_keys_json(1, '密钥名称不能超过 100 个字符');
    }
    // 单用户密钥数量上限（防滥用）
    $cnt = api_key_count_by_user($DB, $uid);
    if($cnt >= 20) {
        api_keys_json(1, '每个用户最多创建 20 个密钥，请先删除不再使用的密钥');
    }
    $ret = api_key_create($DB, $uid, $name);
    if(empty($ret)) {
        api_keys_json(1, '生成密钥失败，请稍后重试');
    }
    api_keys_json(0, '密钥生成成功，请立即复制保存（明文仅展示一次）', [
        'api_key'     => $ret['api_key'],
        'id'          => $ret['id'],
        'key_prefix'  => $ret['key_prefix'],
    ]);
    break;

// ========== 重新生成密钥（旧明文立即失效，新明文仅返回一次） ==========
case 'regen':
    $id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
    if($id <= 0) {
        api_keys_json(1, '参数无效');
    }
    $ret = api_key_regen($DB, $id, $uid);
    if(empty($ret)) {
        api_keys_json(1, '重新生成失败，密钥不存在或无权操作');
    }
    api_keys_json(0, '密钥已重新生成，请立即复制保存（明文仅展示一次）', [
        'api_key'    => $ret['api_key'],
        'key_prefix' => $ret['key_prefix'],
    ]);
    break;

// ========== 删除密钥 ==========
case 'delete':
    $id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
    if($id <= 0) {
        api_keys_json(1, '参数无效');
    }
    $ok = api_key_delete($DB, $id, $uid);
    if(!$ok) {
        api_keys_json(1, '删除失败，密钥不存在或无权操作');
    }
    api_keys_json(0, '已删除');
    break;

// ========== 启用 / 禁用 ==========
case 'toggle':
    $id     = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
    $status = isset($_POST['status']) ? (int)$_POST['status'] : 0;
    if($id <= 0) {
        api_keys_json(1, '参数无效');
    }
    $ok = api_key_set_status($DB, $id, $uid, $status);
    if(!$ok) {
        api_keys_json(1, '操作失败，密钥不存在或无权操作');
    }
    api_keys_json(0, $status ? '已启用' : '已禁用');
    break;

default:
    api_keys_json(1, '未知操作');
    break;
}
